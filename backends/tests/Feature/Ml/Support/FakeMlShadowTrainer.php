<?php

namespace Tests\Feature\Ml\Support;

use App\Domain\Ml\Shadow\MlShadowTrainer;
use App\Models\MlModel;
use Closure;
use Symfony\Component\Process\Process;

/**
 * MlShadowTrainer with no database export and no python. Everything else (run
 * recording, the polling loop, classification, promotion) is the real code.
 *  - $trainerResult: replaces runTrainer() entirely (fn(dir, champion, type): metadata array).
 *  - $processFactory: replaces the python child with any Process (the real polling loop runs on it).
 */
final class FakeMlShadowTrainer extends MlShadowTrainer
{
    public ?Closure $trainerResult = null;

    public ?Closure $processFactory = null;

    public ?Closure $exportHook = null;

    public int $exportRows = 1234;

    public function export(string $symbol, int $horizon, string $timeframe, string $name, string $schema = 'x'): array
    {
        if ($this->exportHook) {
            ($this->exportHook)();
        }
        $directory = (string) config('ml.training.export_dir');
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        file_put_contents($directory.DIRECTORY_SEPARATOR.$name.'.jsonl', "{}\n");
        file_put_contents($directory.DIRECTORY_SEPARATOR.$name.'.manifest.json', '{}');

        return ['data' => $directory.DIRECTORY_SEPARATOR.$name.'.jsonl', 'manifest' => $directory.DIRECTORY_SEPARATOR.$name.'.manifest.json', 'rows' => $this->exportRows];
    }

    protected function runTrainer(array $export, string $directory, ?MlModel $champion, string $type = self::TYPE_REGRESSION): array
    {
        if ($this->trainerResult) {
            return ($this->trainerResult)($directory, $champion, $type);
        }

        return parent::runTrainer($export, $directory, $champion, $type);
    }

    protected function makeProcess(array $command, int $timeoutSeconds): Process
    {
        return $this->processFactory ? ($this->processFactory)($command, $timeoutSeconds) : parent::makeProcess($command, $timeoutSeconds);
    }
}
