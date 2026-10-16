<?php

namespace App\Domain\MarketOpportunityBrain\Forecast;

use Symfony\Component\Process\Process;

/**
 * Runs the FIXED `market_opportunity_brain.cli predict-batch --model chronos2` subprocess once
 * (single model load). Windows go in via a JSON file under a caller-chosen directory (D: for heavy
 * data), results come back via an output JSON file. No shell, fixed argv, no execution of any
 * downloaded text. Never fabricates: on failure returns status != OK and empty results.
 */
final class ChronosBatchRunner
{
    /**
     * @param  list<array{key:string,closes:list<float>}>  $windows
     * @return array{status:string,reason:string,revision:?string,results:array<string,array{p10:float,p50:float,p90:float}>}
     */
    public function run(array $windows, int $horizonBars, string $workDir, int $batchSize = 16, int $timeoutSeconds = 7200): array
    {
        $bin = config('market_opportunity_brain.python_bin');
        if (! is_string($bin) || $bin === '') {
            return $this->fail('PYTHON_BIN_NOT_CONFIGURED');
        }
        if (! is_dir($workDir) && ! mkdir($workDir, 0777, true) && ! is_dir($workDir)) {
            return $this->fail('WORKDIR_NOT_WRITABLE');
        }
        $in = rtrim($workDir, '\\/').DIRECTORY_SEPARATOR.'chronos_in.json';
        $out = rtrim($workDir, '\\/').DIRECTORY_SEPARATOR.'chronos_out.json';
        @unlink($out);
        file_put_contents($in, json_encode(['windows' => $windows], JSON_THROW_ON_ERROR));

        $env = (array) config('market_opportunity_brain.python_env_overrides', []);
        $p = new Process([
            $bin, '-m', 'market_opportunity_brain.cli', 'predict-batch', '--model', 'chronos2',
            '--input-file', $in, '--output-file', $out, '--horizon-bars', (string) $horizonBars,
            '--batch-size', (string) $batchSize,
        ], (string) config('market_opportunity_brain.engine_dir'), $env !== [] ? $env : null, null, $timeoutSeconds);
        $p->run();

        if (! is_file($out)) {
            return $this->fail('NO_OUTPUT: '.mb_substr(trim($p->getOutput().' '.$p->getErrorOutput()), -600));
        }
        $decoded = json_decode((string) file_get_contents($out), true);
        if (! is_array($decoded) || ! isset($decoded['status'])) {
            return $this->fail('MALFORMED_OUTPUT');
        }

        return [
            'status' => (string) $decoded['status'],
            'reason' => (string) ($decoded['reason'] ?? ''),
            'revision' => $decoded['revision'] ?? null,
            'results' => (array) ($decoded['results'] ?? []),
        ];
    }

    private function fail(string $reason): array
    {
        return ['status' => 'MODEL_UNAVAILABLE', 'reason' => $reason, 'revision' => null, 'results' => []];
    }
}
