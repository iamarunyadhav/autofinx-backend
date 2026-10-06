<?php

namespace App\Support\Env;

use Dotenv\Dotenv;

/**
 * Finds and mechanically cleans problems in a Laravel .env file.
 *
 * It works on text and does not boot Laravel, so it still runs when a broken
 * .env would stop the application from starting. Facts it relies on (checked
 * against the installed vlucas/phpdotenv 5.x): when a key appears twice, the
 * LAST occurrence wins; an environment variable defined outside the file
 * (supervisor `environment=`, php-fpm) beats the file entirely.
 *
 * clean() only does edits that cannot change what the app reads (BOM, CRLF,
 * trailing spaces, earlier duplicates of a key). The caller proves that with
 * sameEffective() before writing. Everything else is a finding for a human.
 */
final class EnvDoctor
{
    private const SECRET_NAME = '/(KEY|SECRET|TOKEN|PASS|PASSWORD|PAT|SALT|CREDENTIAL|DSN|PRIVATE)/i';

    /**
     * Logical entries with their physical line span (a quoted value may span lines).
     *
     * @return list<array{name: string, start: int, end: int, raw: string}>
     */
    public static function entries(string $content): array
    {
        $lines = explode("\n", self::normalizeEol(self::stripBom($content)));
        $entries = [];
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=(.*)$/s', $lines[$i], $m)) {
                continue;
            }
            $rest = ltrim($m[2]);
            $end = $i;
            if ($rest !== '' && ($rest[0] === '"' || $rest[0] === "'")) {
                $body = substr($rest, 1);
                while (! self::hasClosingQuote($body, $rest[0]) && $end + 1 < $n) {
                    $end++;
                    $body .= "\n".$lines[$end];
                }
                $rest = $rest[0].$body;
            }
            $entries[] = ['name' => $m[1], 'start' => $i, 'end' => $end, 'raw' => $rest];
            $i = $end;
        }

        return $entries;
    }

    /**
     * @return array{content: string, notes: list<string>, changed: bool}
     */
    public static function clean(string $content): array
    {
        $notes = [];
        $text = $content;
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = self::stripBom($text);
            $notes[] = 'removed a UTF-8 BOM (it silently renamed the first key)';
        }
        if (str_contains($text, "\r")) {
            $text = self::normalizeEol($text);
            $notes[] = 'converted Windows (CRLF) line endings to LF';
        }

        $lines = explode("\n", $text);
        $trimmed = 0;
        foreach ($lines as $i => $line) {
            $r = rtrim($line, " \t");
            if ($r !== $line) {
                $lines[$i] = $r;
                $trimmed++;
            }
        }
        if ($trimmed > 0) {
            $notes[] = "trimmed trailing spaces on {$trimmed} line(s)";
        }

        // Earlier duplicates of a key are dead text: the last one wins.
        $text = implode("\n", $lines);
        $entries = self::entries($text);
        $lastByName = [];
        foreach ($entries as $index => $entry) {
            $lastByName[$entry['name']] = $index;
        }
        $drop = [];
        $removed = [];
        foreach ($entries as $index => $entry) {
            if ($lastByName[$entry['name']] !== $index) {
                foreach (range($entry['start'], $entry['end']) as $lineNo) {
                    $drop[$lineNo] = true;
                }
                $removed[$entry['name']] = ($removed[$entry['name']] ?? 0) + 1;
            }
        }
        if ($drop !== []) {
            $lines = explode("\n", $text);
            $lines = array_values(array_filter($lines, static fn ($_, $k) => ! isset($drop[$k]), ARRAY_FILTER_USE_BOTH));
            foreach ($removed as $name => $count) {
                $notes[] = "removed {$count} earlier duplicate line(s) of {$name} (the last one was already the effective value)";
            }
        } else {
            $lines = explode("\n", $text);
        }

        $text = rtrim(implode("\n", $lines), "\n")."\n";
        if (! str_ends_with($content, "\n") && $notes === []) {
            $notes[] = 'added the missing final newline';
        }

        return ['content' => $text, 'notes' => $notes, 'changed' => $text !== $content];
    }

    /**
     * What Laravel actually reads. Throws Dotenv\Exception\InvalidFileException on a syntax error.
     *
     * @return array<string, string|null>
     */
    public static function effective(string $content): array
    {
        return Dotenv::parse(self::stripBom($content));
    }

    public static function sameEffective(string $before, string $after): bool
    {
        $a = self::effective($before);
        $b = self::effective($after);
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /**
     * Keys that appear more than once, with whether their values differ.
     *
     * @return array<string, array{count: int, differs: bool, effective: string}>
     */
    public static function duplicates(string $content): array
    {
        $by = [];
        foreach (self::entries($content) as $entry) {
            $by[$entry['name']][] = $entry['raw'];
        }
        $effective = self::effective($content);
        $out = [];
        foreach ($by as $name => $raws) {
            if (count($raws) > 1) {
                $values = array_map(static fn ($raw) => self::display($raw), $raws);
                $out[$name] = ['count' => count($raws), 'differs' => count(array_unique($values)) > 1, 'effective' => (string) ($effective[$name] ?? '')];
            }
        }

        return $out;
    }

    public static function mask(string $name, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }
        if (preg_match(self::SECRET_NAME, $name)) {
            return '(set, '.strlen($value).' chars, hidden)';
        }

        $value = str_replace("
", '\n', $value);

        return strlen($value) > 70 ? substr($value, 0, 67).'...' : $value;
    }

    /**
     * Problems that need a human. Pure function of what was read from disk.
     *
     * @param  array<string, string|null>  $effective
     * @param  list<string>  $knownKeys  keys the code, scripts or .env.example refer to
     * @param  array<string, string>  $aliases  config precedence: env('A', env('B')) => ['A' => 'B']
     * @param  array<string, string|null>  $example  parsed .env.example
     * @return list<array{level: string, message: string}>
     */
    public static function findings(array $effective, array $knownKeys, array $aliases = [], array $example = []): array
    {
        $out = [];
        $add = static function (string $level, string $message) use (&$out): void {
            $out[] = ['level' => $level, 'message' => $message];
        };
        $truthy = static fn (?string $v): bool => in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);

        $env = strtolower((string) ($effective['APP_ENV'] ?? ''));
        if ($env !== 'production') {
            $add('WARN', 'APP_ENV is '.($env === '' ? 'not set' : "'{$env}'").', not production.');
        }
        if ($truthy($effective['APP_DEBUG'] ?? null) && $env === 'production') {
            $add('FAIL', 'APP_DEBUG is on in production: error pages can leak secrets and stack traces.');
        }
        if (trim((string) ($effective['APP_KEY'] ?? '')) === '') {
            $add('FAIL', 'APP_KEY is empty: encrypted data and sessions cannot work.');
        }
        if (strtolower((string) ($effective['QUEUE_CONNECTION'] ?? '')) === 'sync') {
            $add('WARN', 'QUEUE_CONNECTION=sync: queued jobs run inside the web request instead of the workers.');
        }
        $cache = strtolower((string) ($effective['CACHE_STORE'] ?? $effective['CACHE_DRIVER'] ?? ''));
        if ($cache === 'array') {
            $add('WARN', 'The cache driver is "array": Cache::lock singleton locks do not work across processes.');
        }
        if (array_key_exists('DB_PASSWORD', $effective) && trim((string) $effective['DB_PASSWORD']) === '') {
            $add('WARN', 'DB_PASSWORD is empty.');
        }
        if (strtolower((string) ($effective['LOG_LEVEL'] ?? '')) === 'debug' && $env === 'production') {
            $add('INFO', 'LOG_LEVEL=debug in production: very large logs and extra disk writes.');
        }
        if (isset($effective['APP_TIMEZONE']) && $effective['APP_TIMEZONE'] !== '') {
            $add('INFO', "APP_TIMEZONE={$effective['APP_TIMEZONE']}: every stored wall time depends on it (the code default is Asia/Colombo). Do not change it on a live system.");
        }

        // Aliases: the first name wins in config, the second is silently ignored.
        foreach ($aliases as $primary => $secondary) {
            if (trim((string) ($effective[$primary] ?? '')) !== '' && trim((string) ($effective[$secondary] ?? '')) !== ''
                && $effective[$primary] !== $effective[$secondary]) {
                $add('WARN', "{$primary}=".self::mask($primary, $effective[$primary])." overrides {$secondary}=".self::mask($secondary, $effective[$secondary]).' in config; the second one has no effect.');
            }
        }

        // Typos and dead keys.
        $known = array_flip($knownKeys);
        $unknown = [];
        foreach (array_keys($effective) as $name) {
            if (! isset($known[$name])) {
                $unknown[] = $name;
            }
        }
        sort($unknown);
        foreach (array_slice($unknown, 0, 40) as $name) {
            $hint = self::closest($name, $knownKeys, $effective);
            $add('INFO', "{$name} is not read by any config, code or script".($hint ? " (did you mean {$hint}?)" : '').'.');
        }
        if (count($unknown) > 40) {
            $add('INFO', '... and '.(count($unknown) - 40).' more unused keys.');
        }

        // Empty where the example expects a value.
        foreach ($example as $name => $exampleValue) {
            if (($exampleValue ?? '') !== '' && array_key_exists($name, $effective) && trim((string) $effective[$name]) === '' && ! preg_match(self::SECRET_NAME, $name)) {
                $add('INFO', "{$name} is empty here but has a value in .env.example.");
            }
        }

        // Secret-looking keys left as placeholders.
        foreach ($effective as $name => $value) {
            if (preg_match(self::SECRET_NAME, $name) && preg_match('/^(your[_-]|change[_-]?me|xxx+|todo|example)/i', trim((string) $value))) {
                $add('WARN', "{$name} looks like a placeholder, not a real secret.");
            }
        }

        return $out;
    }

    private static function closest(string $name, array $knownKeys, array $effective): ?string
    {
        if (strlen($name) < 7) {
            return null;
        }
        $best = null;
        $bestDistance = 3;
        foreach ($knownKeys as $candidate) {
            if (isset($effective[$candidate]) || abs(strlen($candidate) - strlen($name)) > 2) {
                continue;
            }
            $d = levenshtein($name, $candidate);
            if ($d > 0 && $d < $bestDistance) {
                $bestDistance = $d;
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function display(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            return $raw;
        }

        return trim(preg_replace('/\s+#.*$/', '', $raw) ?? $raw);
    }

    private static function hasClosingQuote(string $body, string $quote): bool
    {
        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            if ($body[$i] === '\\') {
                $i++;

                continue;
            }
            if ($body[$i] === $quote) {
                return true;
            }
        }

        return false;
    }

    private static function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    private static function normalizeEol(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
