<?php

/**
 * .env doctor. Run it as the APP user, not root:
 *
 *   runuser -u zcadmin -- /usr/local/bin/ea-php82 env-doctor.php          # report only
 *   runuser -u zcadmin -- /usr/local/bin/ea-php82 env-doctor.php --fix    # report + safe cleanup
 *
 * --fix only removes things that cannot change what the app reads (BOM, CRLF,
 * trailing spaces, earlier duplicates of a key; the LAST one already wins). It
 * backs the file up first, re-parses before and after, and restores the backup
 * if a single effective value would differ. Everything else is reported, never
 * edited. Secret values are never printed.
 *
 * Options: --file=PATH  --no-web  --fix
 * Exit code: 0 clean, 1 warnings, 2 failures or error.
 */

declare(strict_types=1);

$base = __DIR__;
require_once $base.'/vendor/autoload.php';
require_once $base.'/app/Support/Env/EnvDoctor.php';

use App\Support\Env\EnvDoctor;

$options = ['fix' => false, 'web' => true, 'file' => $base.'/.env'];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--fix') {
        $options['fix'] = true;
    } elseif ($arg === '--no-web') {
        $options['web'] = false;
    } elseif (str_starts_with($arg, '--file=')) {
        $options['file'] = substr($arg, 7);
    } else {
        fwrite(STDERR, "Unknown option {$arg}\n");
        exit(2);
    }
}

$worst = 0;
$say = static function (string $level, string $message) use (&$worst): void {
    $worst = max($worst, ['OK' => 0, 'INFO' => 0, 'FIXED' => 0, 'WARN' => 1, 'FAIL' => 2][$level] ?? 0);
    $tag = ['OK' => ' OK  ', 'INFO' => 'INFO ', 'FIXED' => 'FIXED', 'WARN' => 'WARN ', 'FAIL' => 'FAIL '][$level] ?? $level;
    echo "  {$tag} {$message}\n";
};
$section = static function (string $title): void {
    echo "\n== {$title}\n";
};

echo '.env doctor @ '.date('Y-m-d H:i:s T')."\n";
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Run this as the app user (runuser -u zcadmin -- ...), not root: files it writes would become root-owned.\n");
    exit(2);
}
$file = $options['file'];
if (! is_file($file) || ! is_readable($file)) {
    fwrite(STDERR, "Cannot read {$file}\n");
    exit(2);
}
$original = (string) file_get_contents($file);

// 1. Syntax: if Laravel's own parser rejects the file, nothing else is trustworthy.
$section('Syntax');
try {
    $effective = EnvDoctor::effective($original);
    $say('OK', count($effective).' keys parsed by the same parser Laravel uses.');
} catch (Throwable $e) {
    $say('FAIL', 'Laravel cannot parse this file: '.$e->getMessage());
    echo "  Fix that line by hand first; nothing was changed.\n";
    exit(2);
}

// 2. Mechanical problems (and the cleanup).
$section('Mechanical problems');
$duplicates = EnvDoctor::duplicates($original);
foreach ($duplicates as $name => $info) {
    $say($info['differs'] ? 'WARN' : 'INFO', "{$name} appears {$info['count']} times".($info['differs']
        ? '; the values differ, so only the LAST one counts (effective: '.EnvDoctor::mask($name, $info['effective']).').'
        : ' with the same value.'));
}
$cleaned = EnvDoctor::clean($original);
if (! $cleaned['changed']) {
    $say('OK', 'No BOM, CRLF, trailing spaces or duplicate lines.');
} else {
    foreach ($cleaned['notes'] as $note) {
        $say($options['fix'] ? 'FIXED' : 'WARN', ($options['fix'] ? '' : 'would fix: ').$note);
    }
    if ($options['fix']) {
        if (! EnvDoctor::sameEffective($original, $cleaned['content'])) {
            $say('FAIL', 'The cleanup would change an effective value. Nothing was written.');
            exit(2);
        }
        $backupDir = $base.'/storage/env-backups';
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0700, true);
        }
        $backup = $backupDir.'/.env.'.date('Ymd-His');
        file_put_contents($backup, $original);
        chmod($backup, 0600);
        $tmp = $file.'.tmp';
        file_put_contents($tmp, $cleaned['content']);
        chmod($tmp, fileperms($file) & 0777);
        rename($tmp, $file);
        // Prove it on what is now on disk, not on the string in memory.
        if (! EnvDoctor::sameEffective($original, (string) file_get_contents($file))) {
            copy($backup, $file);
            $say('FAIL', 'Verification after writing failed; the backup was restored.');
            exit(2);
        }
        foreach (array_slice(array_reverse(glob($backupDir.'/.env.*') ?: []), 10) as $old) {
            @unlink($old);
        }
        $say('OK', "Written. Every effective value is identical to before. Backup: {$backup}");
        $original = (string) file_get_contents($file);
        $effective = EnvDoctor::effective($original);
    }
}
if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $original)) {
    $say('WARN', 'The file contains control characters.');
}

// 3. What the code actually reads.
$section('Keys vs what the code reads');
$known = [];
$aliases = [];
$scan = static function (string $path, bool $recursive) use (&$known, &$aliases): void {
    $iterator = $recursive
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
        : new DirectoryIterator($path);
    foreach ($iterator as $item) {
        if (! $item->isFile() || $item->getSize() > 2_000_000 || ! in_array($item->getExtension(), ['php', 'sh', 'py', 'conf', 'ini'], true)) {
            continue;
        }
        $text = (string) file_get_contents($item->getPathname());
        if (preg_match_all('/\b(?:env|getenv)\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $text, $m)) {
            array_push($known, ...$m[1]);
        }
        if (preg_match_all('/\b([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)\b/', $text, $m) && $item->getExtension() !== 'php') {
            array_push($known, ...$m[1]);   // shell/python/conf scripts read keys by name
        }
        if (preg_match_all('/\benv\(\s*[\'"]([A-Z0-9_]+)[\'"]\s*,\s*env\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                $aliases[$pair[1]] = $pair[2];
            }
        }
    }
};
foreach (['config', 'app', 'routes', 'bootstrap', 'database'] as $dir) {
    if (is_dir($base.'/'.$dir)) {
        $scan($base.'/'.$dir, true);
    }
}
$scan($base, false);
if (is_dir($base.'/external-predictive-engine')) {
    $scan($base.'/external-predictive-engine', false);
}
$example = [];
if (is_file($base.'/.env.example')) {
    try {
        $example = EnvDoctor::effective((string) file_get_contents($base.'/.env.example'));
        array_push($known, ...array_keys($example));
    } catch (Throwable) {
    }
}
$known = array_values(array_unique($known));
foreach (EnvDoctor::findings($effective, $known, $aliases, $example) as $finding) {
    $say($finding['level'], $finding['message']);
}

// 4. Switches that move money or run jobs, so you can see them at a glance.
$section('Switches (values shown, secrets hidden)');
foreach ([
    'APP_ENV', 'APP_DEBUG', 'APP_TIMEZONE', 'LIVE_TRADING_ENABLED', 'ENABLE_LIVE_TRADING', 'FUTURE_TRADE_ENABLE_LIVE_TRADING',
    'FUTURE_TRADE_FORCE_TESTNET', 'FUTURE_TRADE_PAPER_TRADING', 'AUTOFINX_SCHEDULER_ENABLED', 'AUTOFINX_SCHEDULER_RATIO_BOTS',
    'AUTOFINX_SCHEDULER_ROBOT_SESSIONS', 'AUTOFINX_SCHEDULER_QUICK_SETTLER', 'AUTOFINX_SUPERVISOR_RUNTIME',
    'AUTOFINX_NEXUS_ENABLED', 'AUTOFINX_MARKET_CONTEXT_ENABLED', 'AUTOFINX_ML_SYMBOLS', 'AUTOFINX_ML_MAX_TRAINING_ROWS',
    'AI_BRAIN_ENABLED', 'AI_TRADE_PROVIDER', 'QUEUE_CONNECTION', 'CACHE_STORE', 'CACHE_DRIVER', 'LOG_LEVEL',
] as $name) {
    echo '        '.str_pad($name, 36).(array_key_exists($name, $effective) ? EnvDoctor::mask($name, $effective[$name]) : '(not set: code default)')."\n";
}
if (! empty(array_intersect(['LIVE_TRADING_ENABLED', 'ENABLE_LIVE_TRADING'], array_keys($effective)))) {
    $live = filter_var($effective['ENABLE_LIVE_TRADING'] ?? $effective['LIVE_TRADING_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOL);
    $say('INFO', 'Live spot trading is '.($live ? 'ENABLED: live orders (Ratio Bot included) can be placed.' : 'OFF: every live spot order is refused.'));
}

// 5. File, cache and exposure.
$section('File, config cache and exposure');
$perms = fileperms($file) & 0777;
$say(($perms & 0007) !== 0 ? 'WARN' : 'OK', sprintf('Permissions %04o', $perms).(($perms & 0007) !== 0 ? ': readable by every user on the host (chmod 600 or 640).' : '.'));
if (function_exists('posix_getpwuid') && ($owner = posix_getpwuid((int) fileowner($file)))) {
    $me = posix_getpwuid(posix_geteuid());
    $say($owner['name'] === ($me['name'] ?? '') ? 'OK' : 'WARN', "Owner {$owner['name']}".($owner['name'] === ($me['name'] ?? '') ? '.' : ", not the app user {$me['name']}: edits made as root leave a root-owned file."));
}
$cached = $base.'/bootstrap/cache/config.php';
if (is_file($cached)) {
    if (filemtime($file) > filemtime($cached)) {
        $say('WARN', '.env is newer than bootstrap/cache/config.php: the running app still uses the OLD values. Run: php artisan config:cache');
    } else {
        $say('OK', 'The config cache is newer than .env.');
    }
    $config = @include $cached;
    $bool = static fn ($v): bool => filter_var($v, FILTER_VALIDATE_BOOL);
    if (is_array($config)) {
        $checks = [
            'APP_DEBUG' => [$bool($effective['APP_DEBUG'] ?? false), (bool) ($config['app']['debug'] ?? false), 'app.debug'],
            'LIVE_TRADING_ENABLED' => [$bool($effective['LIVE_TRADING_ENABLED'] ?? false), (bool) ($config['services']['live_trading_enabled'] ?? false), 'services.live_trading_enabled'],
            'AUTOFINX_SCHEDULER_ENABLED' => [$bool($effective['AUTOFINX_SCHEDULER_ENABLED'] ?? true), (bool) ($config['autofinx']['scheduler']['enabled'] ?? true), 'autofinx.scheduler.enabled'],
        ];
        foreach ($checks as $name => [$fromEnv, $fromCache, $key]) {
            if (array_key_exists($name, $effective) && $fromEnv !== $fromCache) {
                $say('WARN', "{$name} in .env is ".($fromEnv ? 'on' : 'off').", but the cached {$key} is ".($fromCache ? 'on' : 'off').'. Run config:cache, then restart the workers.');
            }
        }
    }
} else {
    $say('INFO', 'No config cache (bootstrap/cache/config.php): .env is read on every request.');
}
// A variable set by supervisor or php-fpm beats the .env file completely.
$confs = [];
foreach (explode(',', (string) (getenv('ENV_DOCTOR_SUPERVISOR_GLOB') ?: '/etc/supervisord.d/*.conf,/etc/supervisord.conf,/etc/supervisor/conf.d/*.conf')) as $pattern) {
    array_push($confs, ...(glob($pattern) ?: []));
}
$overrides = 0;
foreach ($confs as $conf) {
    $text = is_readable($conf) ? (string) file_get_contents($conf) : '';
    if ($text !== '' && preg_match_all('/^\s*environment\s*=\s*(.+)$/mi', $text, $lines)) {
        foreach ($lines[1] as $line) {
            if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"?([^",]*)"?/', $line, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as [, $name, $value]) {
                    if (array_key_exists($name, $effective) && (string) $effective[$name] !== $value) {
                        $say('WARN', "{$name} is also set in ".basename($conf).' (supervisor environment=) and OVERRIDES the .env value ('.EnvDoctor::mask($name, $value).' vs '.EnvDoctor::mask($name, $effective[$name]).').');
                        $overrides++;
                    }
                }
            }
        }
    }
}
if ($overrides === 0) {
    $say('OK', 'No supervisor environment= line overrides a key from .env'.($confs === [] ? ' (no readable supervisor config found).' : '.'));
}
if ($options['web'] && ! empty($effective['APP_URL']) && str_starts_with((string) $effective['APP_URL'], 'http')) {
    $root = rtrim((string) $effective['APP_URL'], '/');
    $context = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['verify_peer' => true]]);
    $leaked = false;
    foreach (['/.env', '/backends/.env', '/autofinx-backend/backends/.env'] as $path) {
        $body = @file_get_contents($root.$path, false, $context, 0, 4096);
        if (is_string($body) && str_contains($body, 'APP_KEY=')) {
            $say('FAIL', "{$root}{$path} serves the .env file to anyone. Block it in the web server NOW and rotate every secret in it.");
            $leaked = true;
        }
    }
    if (! $leaked) {
        $say('OK', 'The .env file is not served at the usual web paths under APP_URL.');
    }
}

echo "\n".($worst === 0 ? 'Result: clean.' : ($worst === 1 ? 'Result: warnings above need a look.' : 'Result: FAILURES above need action.'))."\n";
if ($options['fix'] && $cleaned['changed']) {
    echo "Values did not change, so no restart is needed. To refresh the cache anyway:\n";
    echo "  runuser -u zcadmin -- /usr/local/bin/ea-php82 artisan config:cache\n";
}
exit($worst);
