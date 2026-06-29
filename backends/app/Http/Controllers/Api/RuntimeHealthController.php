<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ops\TradingRuntimeHealthService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Throwable;

class RuntimeHealthController extends Controller
{
    public function show(Request $request, TradingRuntimeHealthService $health)
    {
        $workspaceId = $request->query('workspace_id');

        return response()->json($health->snapshot(is_string($workspaceId) ? $workspaceId : null));
    }

    public function repair(Request $request, TradingRuntimeHealthService $health)
    {
        $workspaceId = $request->input('workspace_id');
        $workspaceId = is_string($workspaceId) && $workspaceId !== '' ? $workspaceId : null;
        $script = base_path('scripts/robot-runtime-doctor.sh');

        $output = '';
        $exitCode = 0;
        $method = 'script';

        try {
            if (is_file($script)) {
                @chmod($script, 0755);
                $process = new Process(['bash', $script, '--repair', '--attempts=2', '--sleep=3'], base_path(), null, null, 90);
                $process->run();
                $exitCode = $process->getExitCode() ?? 1;
                $output = trim($process->getOutput()."\n".$process->getErrorOutput());
            } else {
                throw new \RuntimeException('repair script not found');
            }
        } catch (Throwable $e) {
            $method = 'artisan_fallback';
            $output .= "\nScript repair unavailable: ".$e->getMessage();
            foreach ([
                ['queue:restart', []],
                ['market:ws-consume', ['--once' => true, '--market-type' => 'futures', '--store-ohlc' => true, '--ohlc-intervals' => '1m,5m,15m', '--no-dispatch' => true]],
                ['robot-sessions:evaluate', ['--limit' => 50]],
            ] as [$command, $args]) {
                try {
                    Artisan::call($command, $args);
                    $output .= "\n$ ".$command."\n".Artisan::output();
                } catch (Throwable $inner) {
                    $exitCode = 1;
                    $output .= "\nFAILED ".$command.': '.$inner->getMessage();
                }
            }
        }

        $snapshot = $health->snapshot($workspaceId);

        return response()->json([
            'ok' => $exitCode === 0,
            'method' => $method,
            'exit_code' => $exitCode,
            'output' => trim($output),
            'health' => $snapshot,
            'ran_at' => now()->toIso8601String(),
        ], $exitCode === 0 ? 200 : 202);
    }
}
