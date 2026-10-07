<?php

namespace App\Domain\StrategyLab;

/**
 * Static audit of an uploaded Pine script (SPEC §105, SL4). The source is analysed as TEXT only — it is never
 * executed, evaluated or compiled. Output: causal / repaint classification with the exact findings, an
 * indicator inventory mapped to existing V4 feature groups (duplicate detection), and overfit hints.
 *
 * Classification (worst finding wins): INVALID > UNSUPPORTED > REPAINT_RISK > CAUSAL_IF_LAGGED > CAUSAL.
 */
final class PineAuditor
{
    public const CAUSAL = 'CAUSAL';

    public const CAUSAL_IF_LAGGED = 'CAUSAL_IF_LAGGED';

    public const REPAINT_RISK = 'REPAINT_RISK';

    public const UNSUPPORTED = 'UNSUPPORTED';

    public const INVALID = 'INVALID';

    private const SEVERITY = [self::CAUSAL => 0, self::CAUSAL_IF_LAGGED => 1, self::REPAINT_RISK => 2, self::UNSUPPORTED => 3, self::INVALID => 4];

    /** ta.* function -> existing V4 equivalent (null = new information). */
    private const EQUIVALENTS = [
        'ema' => 'G3 ema slopes / spread', 'sma' => 'base / G3 moving averages', 'rsi' => 'base rsi + G3 rsi slope',
        'macd' => 'G3 macd', 'atr' => 'G4 atr_pct / atr_change', 'bb' => 'G4 bb_width / bb_position', 'stdev' => 'G4 volatility',
        'highest' => 'G1 structure (partial)', 'lowest' => 'G1 structure (partial)', 'crossover' => null, 'crossunder' => null,
        'supertrend' => null, 'dmi' => null, 'vwap' => null, 'stoch' => null, 'cci' => null, 'mfi' => null, 'obv' => 'G6 volume (partial)',
        'pivothigh' => 'G1 swings (confirmed)', 'pivotlow' => 'G1 swings (confirmed)', 'tr' => 'G4 atr', 'change' => 'base returns',
        'wma' => null, 'hma' => null, 'vwma' => null, 'kc' => 'G4 (partial)', 'sar' => null, 'mom' => 'G3 momentum', 'roc' => 'base returns',
    ];

    public static function normalize(string $source): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $source) ?: [];

        return trim(implode("\n", array_map('rtrim', $lines)));
    }

    /** @return array<string, mixed> */
    public function audit(string $source): array
    {
        $code = $this->stripComments($source);
        $findings = [];
        $add = function (string $class, string $construct, string $detail) use (&$findings): void {
            $findings[] = ['classification' => $class, 'construct' => $construct, 'detail' => $detail];
        };

        $version = preg_match('/\/\/\s*@version\s*=\s*(\d+)/', $source, $m) ? (int) $m[1] : null;
        $isStrategy = (bool) preg_match('/\bstrategy\s*\(/', $code);
        $isIndicator = (bool) preg_match('/\b(indicator|study)\s*\(/', $code);
        if ($version === null) {
            $add(self::INVALID, '//@version', 'No //@version declaration: not a recognisable Pine script.');
        } elseif ($version < 4) {
            $add(self::UNSUPPORTED, '//@version='.$version, 'Pine v'.$version.' is not supported (v4-v6 only).');
        }
        if (! $isStrategy && ! $isIndicator) {
            $add(self::INVALID, 'strategy()/indicator()', 'Neither strategy() nor indicator() declaration found.');
        }

        foreach ($this->securityCalls($code) as $call) {
            $lookaheadOn = str_contains($call, 'lookahead_on');
            $lagged = (bool) preg_match('/\[\s*1\s*\]/', $call);
            if ($lookaheadOn && ! $lagged) {
                $add(self::REPAINT_RISK, 'request.security', 'lookahead_on without [1] reads the FUTURE higher-timeframe value: '.$this->clip($call));
            } elseif (! $lookaheadOn && ! $lagged) {
                $add(self::REPAINT_RISK, 'request.security', 'reads the still-forming higher-timeframe bar (no [1]); live values change until the HTF bar closes: '.$this->clip($call));
            } elseif (! $lookaheadOn && $lagged) {
                $add(self::CAUSAL, 'request.security', 'f()[1] with lookahead_off: causal; TradingView history sees one HTF bar older than live (backtest vs live mismatch): '.$this->clip($call));
            } else {
                $add(self::CAUSAL, 'request.security', 'f()[1] with lookahead_on: standard non-repainting form: '.$this->clip($call));
            }
        }
        if (preg_match('/request\.security_lower_tf\s*\(/', $code)) {
            $add(self::UNSUPPORTED, 'request.security_lower_tf', 'Intrabar (lower timeframe) arrays are not reproducible on 5m research data.');
        }
        if (preg_match('/\bta\.pivot(high|low)\s*\(/', $code)) {
            $add(self::CAUSAL_IF_LAGGED, 'ta.pivothigh/pivotlow', 'Pivots are confirmed only after the right-side bars; usable only with that confirmation lag.');
        }
        if (preg_match('/zigzag/i', $code)) {
            $add(self::REPAINT_RISK, 'zigzag', 'ZigZag logic usually redraws its last leg (future confirmation).');
        }
        if (preg_match('/\bvarip\b/', $code) || preg_match('/barstate\.isrealtime/', $code)) {
            $add(self::REPAINT_RISK, 'varip / barstate.isrealtime', 'Realtime-only state: behaves differently on history and live.');
        }
        if (preg_match('/calc_on_every_tick\s*=\s*true/', $code) && ! preg_match('/barstate\.isconfirmed/', $code)) {
            $add(self::REPAINT_RISK, 'calc_on_every_tick', 'Signals evaluated intrabar without barstate.isconfirmed can appear and disappear.');
        }
        if (preg_match('/barstate\.isconfirmed/', $code)) {
            $add(self::CAUSAL, 'barstate.isconfirmed', 'Signals are gated on confirmed (closed) bars.');
        }
        if (preg_match('/process_orders_on_close\s*=\s*true/', $code)) {
            $add(self::CAUSAL, 'process_orders_on_close', 'TradingView fills at the signal bar close; AutoFinX normalizes to the next executable 1m open.');
        }
        if (preg_match('/\b(timenow)\b/', $code)) {
            $add(self::CAUSAL, 'timenow', 'Wall-clock time used (only harmful if it drives signal logic).');
        }
        if (preg_match('/(webhook|alert_message|alert\s*\(|3commas|bot_id|token)/i', $code)) {
            $add(self::CAUSAL, 'alerts / webhook', 'Live order plumbing present. The lab never sends alerts or orders; it is ignored here.');
        }

        preg_match_all('/\bta\.([a-z_]+)\s*\(/', $code, $ta);
        $inventory = [];
        foreach (array_count_values($ta[1]) as $fn => $count) {
            $inventory[] = ['function' => 'ta.'.$fn, 'calls' => $count, 'existing_v4_equivalent' => self::EQUIVALENTS[$fn] ?? null];
        }
        usort($inventory, fn ($a, $b) => strcmp($a['function'], $b['function']));
        $inputs = preg_match_all('/\binput(\.[a-z]+)?\s*\(/', $code);
        $timeframe = preg_match_all('/request\.security\s*\(\s*[^,]+,\s*"?([A-Za-z0-9]+)"?/', $code, $tf) ? array_values(array_unique($tf[1])) : [];

        $classification = self::CAUSAL;
        foreach ($findings as $f) {
            if (self::SEVERITY[$f['classification']] > self::SEVERITY[$classification]) {
                $classification = $f['classification'];
            }
        }

        return [
            'classification' => $classification,
            'pine_version' => $version,
            'script_type' => $isStrategy ? 'strategy' : ($isIndicator ? 'indicator' : null),
            'findings' => $findings,
            'indicators' => $inventory,
            'inputs_count' => $inputs,
            'overfit_risk' => $inputs > 25 ? 'HIGH' : ($inputs > 12 ? 'MEDIUM' : 'LOW'),
            'htf_reads' => $timeframe,
            'lines' => substr_count($source, "\n") + 1,
            'executed' => false,
        ];
    }

    private function stripComments(string $source): string
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $source) ?: [] as $line) {
            // Remove // comments that are not inside a string literal.
            $inString = null;
            $clean = '';
            for ($i = 0, $n = strlen($line); $i < $n; $i++) {
                $ch = $line[$i];
                if ($inString === null && ($ch === '"' || $ch === "'")) {
                    $inString = $ch;
                } elseif ($inString === $ch) {
                    $inString = null;
                } elseif ($inString === null && $ch === '/' && ($line[$i + 1] ?? '') === '/') {
                    break;
                }
                $clean .= $ch;
            }
            $out[] = $clean;
        }

        return implode("\n", $out);
    }

    /** @return list<string> */
    private function securityCalls(string $code): array
    {
        $calls = [];
        $offset = 0;
        while (preg_match('/request\.security\s*\(/', $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $i = $start + strlen($m[0][0]);
            $depth = 1;
            while ($i < strlen($code) && $depth > 0) {
                $depth += $code[$i] === '(' ? 1 : ($code[$i] === ')' ? -1 : 0);
                $i++;
            }
            $calls[] = substr($code, $start, $i - $start);
            $offset = $i;
        }

        return $calls;
    }

    private function clip(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_strlen($text) > 160 ? mb_substr($text, 0, 157).'...' : $text;
    }
}
