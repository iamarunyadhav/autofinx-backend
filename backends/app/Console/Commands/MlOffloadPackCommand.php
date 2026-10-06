<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\MlModelRegistry;
use App\Models\MlChampion;
use App\Models\MlModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Packs the models trained on this (training) machine into a directory:
 * bundle.json (registry rows) plus models/<id>/ artifacts, ready to be copied
 * to the server and installed with ml:offload:install.
 */
final class MlOffloadPackCommand extends Command
{
    protected $signature = 'ml:offload:pack {dir : Output directory (created, must be empty or new)}';

    protected $description = 'Pack locally trained shadow models (rows + artifacts) for installation on the server.';

    private const STATUSES = ['CHAMPION', 'CANDIDATE', 'REJECTED', 'RETIRED'];

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/\\');
        if (is_dir($dir) && count(File::files($dir)) + count(File::directories($dir)) > 0) {
            $this->error("{$dir} is not empty.");

            return self::FAILURE;
        }
        File::ensureDirectoryExists($dir.DIRECTORY_SEPARATOR.'models');

        $models = [];
        foreach (MlModel::query()->whereIn('status', self::STATUSES)->orderBy('created_at')->get() as $model) {
            $files = array_values(MlModelRegistry::artifactFiles($model)) + [99 => 'metadata.json'];
            $source = (string) $model->artifact_dir;
            $missing = array_filter($files, static fn (string $f): bool => ! is_file($source.DIRECTORY_SEPARATOR.$f));
            if ($source === '' || $missing !== []) {
                $this->warn("Skipped {$model->symbol} h{$model->horizon_minutes} {$model->id}: artifact files missing (".implode(',', $missing).').');

                continue;
            }
            $target = $dir.DIRECTORY_SEPARATOR.'models'.DIRECTORY_SEPARATOR.$model->id;
            File::ensureDirectoryExists($target);
            foreach ($files as $file) {
                File::copy($source.DIRECTORY_SEPARATOR.$file, $target.DIRECTORY_SEPARATOR.$file);
            }
            $this->line(sprintf('  %-9s h%-5s %-9s %s', $model->symbol, $model->horizon_minutes, $model->status, (string) $model->decision_reason));
            $models[] = ['files' => array_values($files)] + $model->getAttributes();
        }
        $champions = MlChampion::query()->get(['symbol', 'horizon_minutes', 'model_id'])->map->toArray()->all();

        file_put_contents($dir.DIRECTORY_SEPARATOR.'bundle.json', json_encode([
            'format' => 1, 'packed_at' => now('UTC')->toIso8601String(), 'models' => $models, 'champions' => $champions,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $counts = array_count_values(array_column($models, 'status'));
        $this->info('Packed '.count($models).' model(s) into '.$dir.' '.json_encode($counts));

        return self::SUCCESS;
    }
}
