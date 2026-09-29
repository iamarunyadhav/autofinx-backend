<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

final class PredictionLegacyModelsArchiveCommand extends Command
{
    protected $signature = 'prediction:legacy-models:archive {--apply : Move legacy .txt/.json model artifacts into models/legacy}';
    protected $description = 'Archive old external predictor artifacts. Dry-run by default; never publishes a fresh model.';

    public function handle(): int
    {
        $source = rtrim((string) config('prediction_fresh_data.legacy_model_dir'), DIRECTORY_SEPARATOR);
        if (! is_dir($source)) {
            $this->line('No legacy model directory exists; nothing to archive.');
            return self::SUCCESS;
        }
        $files = array_values(array_filter(glob($source.DIRECTORY_SEPARATOR.'*') ?: [], static fn (string $file): bool => is_file($file) && preg_match('/\.(txt|json)$/i', $file) === 1));
        if ($files === []) {
            $this->line('No legacy model artifacts found; nothing to archive.');
            return self::SUCCESS;
        }
        $destination = $source.DIRECTORY_SEPARATOR.'legacy';
        foreach ($files as $file) {
            $this->line(($this->option('apply') ? 'Archiving' : 'Would archive').' '.basename($file));
        }
        if (! $this->option('apply')) {
            $this->line('Dry run only. Re-run with --apply after confirming the external predictor no longer points at this directory.');
            return self::SUCCESS;
        }
        if (! is_dir($destination) && ! mkdir($destination, 0750, true) && ! is_dir($destination)) {
            throw new RuntimeException('Unable to create legacy archive directory.');
        }
        foreach ($files as $file) {
            $target = $destination.DIRECTORY_SEPARATOR.basename($file);
            if (file_exists($target) || ! rename($file, $target)) {
                throw new RuntimeException('Unable to archive legacy artifact: '.basename($file));
            }
        }
        $this->info('Legacy artifacts archived. This command does not alter supervisor or predictor configuration.');
        return self::SUCCESS;
    }
}
