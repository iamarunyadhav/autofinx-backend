<?php

namespace App\Domain\Signals;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class StrategyResearchLabService
{
    public function importScript(string $name, string $script, string $source = 'pine'): array
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $script));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $normalized)), fn (string $line): bool => $line !== ''));
        $title = $name !== '' ? $name : $this->extractScriptTitle($normalized);
        $type = Str::contains($normalized, 'strategy(') ? 'strategy' : 'indicator';
        $version = preg_match('/@version\s*=\s*(\d+)/i', $normalized, $matches) ? (string) $matches[1] : null;
        $detectedIndicators = $this->detectedIndicators($normalized);
        $signalRules = array_values(array_slice(array_filter($lines, fn (string $line): bool => preg_match('/\b(crossover|crossunder|buy|sell|long|short|entry|exit)\b/i', $line) === 1), 0, 12));

        return [
            'id' => (string) Str::uuid(),
            'name' => $title !== '' ? $title : 'Imported Script',
            'source' => strtolower(trim($source)) ?: 'pine',
            'scriptType' => $type,
            'version' => $version,
            'scriptBody' => $normalized,
            'lineCount' => count($lines),
            'detectedIndicators' => $detectedIndicators,
            'signalRules' => $signalRules,
            'overlayHints' => array_map(fn (string $indicator): array => [
                'label' => strtoupper($indicator),
                'kind' => in_array($indicator, ['ema', 'sma', 'vwap'], true) ? 'trend' : 'signal',
            ], array_slice($detectedIndicators, 0, 6)),
            'importedAt' => now()->toIso8601String(),
        ];
    }

    public function importTradeDataset(string $name, string $payload, string $format = 'csv'): array
    {
        $rows = strtolower($format) === 'json'
            ? $this->parseJsonDataset($payload)
            : $this->parseCsvDataset($payload);

        $normalizedRows = [];
        foreach (array_slice($rows, 0, 300) as $row) {
            $normalizedRows[] = $this->normalizeTradeRow((array) $row);
        }

        $wins = count(array_filter($normalizedRows, fn (array $row): bool => ($row['outcome'] ?? 'unknown') === 'win'));
        $losses = count(array_filter($normalizedRows, fn (array $row): bool => ($row['outcome'] ?? 'unknown') === 'loss'));

        return [
            'id' => (string) Str::uuid(),
            'name' => trim($name) !== '' ? trim($name) : 'Imported Trade Dataset',
            'format' => strtolower(trim($format)) === 'json' ? 'json' : 'csv',
            'rowCount' => count($normalizedRows),
            'wins' => $wins,
            'losses' => $losses,
            'winRate' => ($wins + $losses) > 0 ? round($wins / ($wins + $losses), 4) : null,
            'rows' => $normalizedRows,
            'importedAt' => now()->toIso8601String(),
        ];
    }

    public function summarizeImportedDatasets(array $datasets): array
    {
        $summary = [];
        foreach ($datasets as $dataset) {
            foreach ((array) ($dataset['rows'] ?? []) as $row) {
                foreach ((array) ($row['indicatorFlags'] ?? []) as $key => $value) {
                    if (! $value) {
                        continue;
                    }

                    $summary[$key] ??= [
                        'key' => $key,
                        'samples' => 0,
                        'wins' => 0,
                        'losses' => 0,
                        'avgPnl' => 0.0,
                    ];
                    $summary[$key]['samples']++;
                    $summary[$key]['avgPnl'] += (float) ($row['netPnl'] ?? 0.0);
                    if (($row['outcome'] ?? 'unknown') === 'win') {
                        $summary[$key]['wins']++;
                    } elseif (($row['outcome'] ?? 'unknown') === 'loss') {
                        $summary[$key]['losses']++;
                    }
                }
            }
        }

        foreach ($summary as $key => $item) {
            $summary[$key]['winRate'] = ($item['wins'] + $item['losses']) > 0 ? round($item['wins'] / ($item['wins'] + $item['losses']), 4) : null;
            $summary[$key]['avgPnl'] = $item['samples'] > 0 ? round($item['avgPnl'] / $item['samples'], 4) : 0.0;
        }

        uasort($summary, fn (array $left, array $right): int => [$right['samples'], $right['winRate'] ?? 0] <=> [$left['samples'], $left['winRate'] ?? 0]);

        return array_values($summary);
    }

    private function extractScriptTitle(string $script): string
    {
        if (preg_match('/(?:indicator|strategy)\(\s*[\'"]([^\'"]+)[\'"]/i', $script, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function detectedIndicators(string $script): array
    {
        $map = [
            'ema' => ['ema(', 'ta.ema', 'ema '],
            'sma' => ['sma(', 'ta.sma', 'sma '],
            'vwap' => ['vwap', 'ta.vwap'],
            'rsi' => ['rsi(', 'ta.rsi'],
            'macd' => ['macd', 'ta.macd'],
            'adx' => ['adx', 'ta.adx'],
            'atr' => ['atr', 'ta.atr'],
            'volume' => ['volume', 'vol '],
            'utbot' => ['ut bot', 'utbot'],
            'orderblock' => ['order block', 'orderblock'],
            'msb' => ['msb', 'market structure break'],
        ];
        $lower = strtolower($script);
        $detected = [];
        foreach ($map as $key => $tokens) {
            foreach ($tokens as $token) {
                if (str_contains($lower, strtolower($token))) {
                    $detected[] = $key;
                    break;
                }
            }
        }

        return array_values(array_unique($detected));
    }

    private function parseCsvDataset(string $payload): array
    {
        $rows = preg_split('/\r\n|\r|\n/', trim($payload)) ?: [];
        if (count($rows) < 2) {
            return [];
        }

        $headers = str_getcsv(array_shift($rows) ?: '');
        $normalizedHeaders = array_map(fn ($value) => Str::snake(trim((string) $value)), $headers);
        $result = [];

        foreach ($rows as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line);
            $result[] = array_combine($normalizedHeaders, array_pad($values, count($normalizedHeaders), null)) ?: [];
        }

        return $result;
    }

    private function parseJsonDataset(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_is_list($decoded) ? $decoded : (array) ($decoded['rows'] ?? []);
    }

    private function normalizeTradeRow(array $row): array
    {
        $netPnl = $this->asFloat(Arr::get($row, 'net_pnl', Arr::get($row, 'pnl', Arr::get($row, 'profit_loss', 0))));
        $result = strtolower(trim((string) Arr::get($row, 'result', Arr::get($row, 'outcome', ''))));
        if ($result === '') {
            $result = $netPnl > 0 ? 'win' : ($netPnl < 0 ? 'loss' : 'flat');
        }

        $flagMap = [
            'vwap' => ['vwap', 'price_above_vwap', 'vwap_aligned'],
            'ema' => ['ema', 'ema_aligned', 'ema_stack'],
            'adx' => ['adx', 'adx_ready'],
            'macd' => ['macd', 'macd_bullish', 'macd_direction'],
            'rsi' => ['rsi', 'rsi_ok', 'rsi_window'],
            'volume' => ['volume', 'volume_ok', 'volume_confirmed'],
            'htf' => ['htf', 'higher_timeframe', 'trend_filter'],
            'structure' => ['structure', 'msb', 'orderblock', 'order_block'],
            'sequence' => ['sequence', 'directional_sequence'],
        ];
        $indicatorFlags = [];
        foreach ($flagMap as $key => $aliases) {
            $indicatorFlags[$key] = false;
            foreach ($aliases as $alias) {
                $value = Arr::get($row, $alias);
                if ($this->truthy($value)) {
                    $indicatorFlags[$key] = true;
                    break;
                }
            }
        }

        return [
            'symbol' => strtoupper(trim((string) Arr::get($row, 'symbol', 'UNKNOWN'))),
            'timeframe' => trim((string) Arr::get($row, 'timeframe', '5m')),
            'side' => strtoupper(trim((string) Arr::get($row, 'side', Arr::get($row, 'signal', 'UNKNOWN')))),
            'entry' => $this->asFloat(Arr::get($row, 'entry', Arr::get($row, 'entry_price', 0))),
            'exit' => $this->asFloat(Arr::get($row, 'exit', Arr::get($row, 'exit_price', 0))),
            'confidence' => $this->asFloat(Arr::get($row, 'confidence', 0)),
            'netPnl' => $netPnl,
            'outcome' => in_array($result, ['win', 'loss', 'flat'], true) ? $result : 'unknown',
            'indicatorFlags' => $indicatorFlags,
        ];
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'pass', 'buy', 'bullish'], true);
    }

    private function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
