<?php

namespace App\Console\Commands;

use App\Domain\Ml\Shadow\MlModelRegistry;
use App\Models\MlChampion;
use App\Models\MlModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Installs models packed by ml:offload:pack. Without --apply it only reports.
 * Every artifact is verified against its recorded sha256 first. A CHAMPION from
 * the bundle becomes champion only when the server has none yet for that
 * symbol and horizon (it goes through the normal registry promotion, so the
 * champion manifest is written for this server paths); otherwise it is kept as
 * a CANDIDATE and nothing live changes. Shadow only: execution is never read.
 */
final class MlOffloadInstallCommand extends Command
{
    protected $signature = 'ml:offload:install {dir : Directory made by ml:offload:pack} {--apply : Actually install (default is a report only)}';

    protected $description = 'Install locally trained shadow models on this server (checksums verified, no live impact).';

    public function handle(MlModelRegistry $registry): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/\\');
        $bundlePath = $dir.DIRECTORY_SEPARATOR.'bundle.json';
        if (! is_file($bundlePath)) {
            $this->error("No bundle.json in {$dir}.");

            return self::FAILURE;
        }
        $bundle = json_decode((string) file_get_contents($bundlePath), true, 512, JSON_THROW_ON_ERROR);
        $apply = (bool) $this->option('apply');
        $root = rtrim((string) config('ml.training.artifact_dir'), '/\\');
        $summary = ['installed' => 0, 'promoted' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ((array) ($bundle['models'] ?? []) as $row) {
            $label = "{$row['symbol']} h{$row['horizon_minutes']} {$row['status']} {$row['model_version']}";
            if (MlModel::query()->whereKey($row['id'])->orWhere('model_version', $row['model_version'])->exists()) {
                $this->line("  skip     {$label}: already on this server.");
                $summary['skipped']++;

                continue;
            }
            $source = $dir.DIRECTORY_SEPARATOR.'models'.DIRECTORY_SEPARATOR.$row['id'];
            $checksums = (array) json_decode((string) $row['artifact_checksums'], true);
            $bad = $this->checksumProblem($row, $source, $checksums);
            if ($bad !== null) {
                $this->error("  FAILED   {$label}: {$bad}");
                $summary['failed']++;

                continue;
            }
            $hasChampion = MlChampion::query()->where('symbol', $row['symbol'])->where('horizon_minutes', $row['horizon_minutes'])->exists();
            $wantsChampion = in_array($row['status'], ['CHAMPION', 'CANDIDATE'], true);
            $willPromote = $row['status'] === 'CHAMPION' && ! $hasChampion;
            $note = $willPromote ? 'will become champion' : ($wantsChampion ? 'kept as CANDIDATE (server already has a champion)' : 'recorded as '.$row['status']);
            if (! $apply) {
                $this->line("  would    {$label}: {$note}.");

                continue;
            }

            try {
                $target = $root.DIRECTORY_SEPARATOR.MlModelRegistry::championKey($row['symbol'], (int) $row['horizon_minutes']).DIRECTORY_SEPARATOR.$row['id'];
                File::ensureDirectoryExists($target);
                foreach ((array) $row['files'] as $file) {
                    File::copy($source.DIRECTORY_SEPARATOR.$file, $target.DIRECTORY_SEPARATOR.$file);
                }
                $insert = collect($row)->except(['files'])->all();
                $insert['artifact_dir'] = $target;
                $insert['status'] = $wantsChampion ? 'CANDIDATE' : $row['status'];
                $insert['promoted_at'] = null;
                $insert['retired_at'] = $row['status'] === 'RETIRED' ? ($row['retired_at'] ?? now('UTC')) : null;
                $insert['previous_champion_id'] = null;
                DB::table('ml_models')->insert($insert);
                $summary['installed']++;
                if ($willPromote) {
                    $registry->promote(MlModel::query()->findOrFail($row['id']), 'offloaded_training: '.($row['decision_reason'] ?? 'trained on a separate machine'), (array) json_decode((string) ($row['comparison'] ?? '[]'), true));
                    $summary['promoted']++;
                }
                $this->info("  installed {$label}: {$note}.");
            } catch (Throwable $exception) {
                $this->error("  FAILED   {$label}: ".$exception->getMessage());
                $summary['failed']++;
            }
        }

        $this->line(($apply ? 'Done: ' : 'Report only (add --apply to install): ').json_encode($summary));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $row @param array<string, string> $checksums */
    private function checksumProblem(array $row, string $source, array $checksums): ?string
    {
        $files = (array) $row['files'];
        if ($files === [] || $checksums === []) {
            return 'no files or checksums recorded.';
        }
        $byFile = [];
        foreach (array_merge(MlModelRegistry::ARTIFACT_FILES, MlModelRegistry::CLASSIFIER_ARTIFACT_FILES, ['metadata' => 'metadata.json']) as $role => $file) {
            $byFile[$file] = $checksums[$role] ?? null;
        }
        foreach ($files as $file) {
            $path = $source.DIRECTORY_SEPARATOR.$file;
            if (! is_file($path)) {
                return "file {$file} is missing from the bundle.";
            }
            $expected = $byFile[$file] ?? null;
            if ($expected === null || hash_file('sha256', $path) !== $expected) {
                return "checksum mismatch for {$file} (damaged copy).";
            }
        }

        return null;
    }
}
