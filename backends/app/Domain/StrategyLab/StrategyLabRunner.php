<?php

namespace App\Domain\StrategyLab;

use App\Models\StrategyLabCandidate;
use App\Models\StrategyLabRun;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Executes one Strategy Lab run: the reviewed Python adapter (v4.lab_runner) as a child process with a fixed
 * argument list (no shell, no user text in the command — only the run id path and a registered adapter key).
 * Parses "PROGRESS <pct> <phase>" lines into phase / progress / heartbeat, keeps a log tail, honours cancel,
 * and stores the result summary + verdict. Research only: no order path.
 */
class StrategyLabRunner
{
    private const EXIT_CODES = [3 => 'DATA_UNAVAILABLE', 4 => 'ADAPTER_UNKNOWN'];

    public function __construct(private readonly StrategyLabService $service) {}

    public function execute(StrategyLabRun $run): void
    {
        $claimed = StrategyLabRun::query()->whereKey($run->id)->where('status', StrategyLabRun::QUEUED)->update([
            'status' => StrategyLabRun::RUNNING, 'phase' => 'preparing_data', 'started_at' => StrategyLabRun::utcNow(),
            'heartbeat_at' => StrategyLabRun::utcNow(), 'updated_at' => StrategyLabRun::utcNow(),
        ]);
        if ($claimed !== 1) {
            return;   // cancelled while queued, or already taken
        }
        $run->refresh();
        $out = $this->service->artifactDir($run);
        File::ensureDirectoryExists($out);
        $mode = $run->kind === StrategyLabRun::QUICK ? 'quick' : 'deep';
        $timeout = max(60, (int) config("strategy_lab.timeout_seconds.{$mode}", 900));
        $lines = [];
        try {
            $process = $this->makeProcess($this->command($run, $mode, $out), $timeout);
            $cancelled = $this->wait($process, $run, $lines);
            if ($cancelled) {
                $this->finish($run, StrategyLabRun::CANCELLED, 'CANCELLED', 'Stopped by the operator.', $lines);

                return;
            }
            if (! $process->isSuccessful()) {
                $code = self::EXIT_CODES[$process->getExitCode()] ?? 'PYTHON_EXIT';
                $this->finish($run, StrategyLabRun::FAILED, $code, mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), -1500), $lines);

                return;
            }
            $resultPath = $out.DIRECTORY_SEPARATOR.'result.json';
            if (! is_file($resultPath)) {
                $this->finish($run, StrategyLabRun::FAILED, 'NO_RESULT', 'The runner produced no result.json.', $lines);

                return;
            }
            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $sha = is_file($out.DIRECTORY_SEPARATOR.'result.sha256') ? trim((string) file_get_contents($out.DIRECTORY_SEPARATOR.'result.sha256')) : null;
            $now = StrategyLabRun::utcNow();
            StrategyLabRun::query()->whereKey($run->id)->update([
                'status' => StrategyLabRun::COMPLETED, 'phase' => 'done', 'progress_pct' => 100, 'verdict' => (string) ($result['verdict'] ?? 'UNKNOWN'),
                'summary' => json_encode($result), 'result_sha256' => $sha, 'finished_at' => $now, 'heartbeat_at' => $now,
                'log_tail' => implode("\n", $lines), 'updated_at' => $now,
            ]);
            $candidate = StrategyLabCandidate::query()->find($run->candidate_id);
            $candidate?->advanceTo($run->kind === StrategyLabRun::QUICK ? StrategyLabCandidate::QUICK_TESTED : StrategyLabCandidate::DEEP_TESTED);
        } catch (ProcessTimedOutException) {
            $this->finish($run, StrategyLabRun::FAILED, 'TIMEOUT', "Exceeded {$timeout} s.", $lines);
        } catch (Throwable $e) {
            $this->finish($run, StrategyLabRun::FAILED, 'OTHER', mb_substr($e->getMessage(), 0, 1500), $lines);
        }
    }

    /** @return list<string> */
    protected function command(StrategyLabRun $run, string $mode, string $out): array
    {
        return [(string) config('strategy_lab.python_bin'), '-m', 'v4.lab_runner', '--adapter', (string) $run->adapter,
            '--mode', $mode, '--work', (string) config('strategy_lab.work_dir'), '--out', $out];
    }

    /** Seam for tests. */
    protected function makeProcess(array $command, int $timeoutSeconds): Process
    {
        return new Process($command, (string) config('strategy_lab.engine_dir'), null, null, $timeoutSeconds);
    }

    /** @param list<string> $lines */
    private function wait(Process $process, StrategyLabRun $run, array &$lines): bool
    {
        $keep = max(1, (int) config('strategy_lab.log_tail_lines', 40));
        $pollEvery = max(0.05, (int) config('strategy_lab.poll_interval_ms', 1000) / 1000);
        $carry = '';
        $collect = function () use ($process, &$lines, &$carry, $keep, $run): void {
            $chunk = $process->getIncrementalOutput().$process->getIncrementalErrorOutput();
            if ($chunk === '') {
                return;
            }
            $parts = preg_split('/\r\n|\n|\r/', $carry.$chunk) ?: [];
            $carry = (string) array_pop($parts);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^PROGRESS\s+(\d+)\s+(.+)$/', $part, $m)) {
                    $phase = str_starts_with($m[2], 'loading') ? 'preparing_data' : (str_starts_with($m[2], 'simulating') ? 'running' : (str_starts_with($m[2], 'scoring') ? 'scoring' : 'running'));
                    StrategyLabRun::query()->whereKey($run->id)->update(['progress_pct' => min(99, (int) $m[1]), 'phase' => $phase,
                        'heartbeat_at' => StrategyLabRun::utcNow(), 'updated_at' => StrategyLabRun::utcNow()]);
                }
                $lines[] = mb_substr($part, 0, 300);
            }
            $lines = array_slice($lines, -$keep);
        };
        $process->start();
        $lastPoll = 0.0;
        while ($process->isRunning()) {
            $process->checkTimeout();
            $now = microtime(true);
            if ($now - $lastPoll >= $pollEvery) {
                $lastPoll = $now;
                $collect();
                $cancel = StrategyLabRun::query()->whereKey($run->id)->value('cancel_requested_at');
                StrategyLabRun::query()->whereKey($run->id)->update(['heartbeat_at' => StrategyLabRun::utcNow()]);
                if ($cancel !== null) {
                    $process->stop(10);
                    $collect();

                    return true;
                }
            }
            usleep(50_000);
        }
        $collect();
        if (trim($carry) !== '') {
            $lines[] = mb_substr(trim($carry), 0, 300);
        }

        return false;
    }

    /** @param list<string> $lines */
    private function finish(StrategyLabRun $run, string $status, string $code, string $message, array $lines): void
    {
        $now = StrategyLabRun::utcNow();
        StrategyLabRun::query()->whereKey($run->id)->update([
            'status' => $status, 'phase' => 'done', 'error_code' => $code, 'error_message' => $message,
            'log_tail' => implode("\n", $lines), 'finished_at' => $now, 'updated_at' => $now,
        ]);
    }
}
