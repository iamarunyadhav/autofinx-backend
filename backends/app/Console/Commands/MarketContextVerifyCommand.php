<?php

namespace App\Console\Commands;

use App\Casts\UtcDateTime;
use App\Domain\MarketContext\Data\ContextQuery;
use App\Domain\MarketContext\SnapshotBuilder;
use App\Domain\MarketContext\SnapshotStore;
use App\Domain\MarketContext\Support\ProfileRegistry;
use App\Models\MarketContextEvent;
use App\Models\MarketContextEventInstrument;
use App\Models\MarketContextMetric;
use App\Models\MarketContextSnapshot;
use App\Models\MarketContextSourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only health check of the Market Context module (writes nothing, fetches
 * nothing). Exit code 1 when any check FAILs, so it can be scripted after a deploy.
 */
class MarketContextVerifyCommand extends Command
{
    protected $signature = 'market-context:verify';

    protected $description = 'Read-only health check of the Market Context module: schema, config, sources, point-in-time sanity, snapshots.';

    private const PASS = 'PASS';

    private const WARN = 'WARN';

    private const FAIL = 'FAIL';

    /** @var array<string, int> */
    private array $totals = [self::PASS => 0, self::WARN => 0, self::FAIL => 0];

    private const TABLES = ['mctx_events', 'mctx_event_instruments', 'mctx_metrics', 'mctx_snapshots', 'mctx_source_health'];

    public function handle(ProfileRegistry $profiles, SnapshotBuilder $builder, SnapshotStore $store): int
    {
        $this->line('Market Context verify @ '.now()->toDateTimeString().' (app timezone '.config('app.timezone').')');

        $this->section('Platform');
        $enabled = (bool) config('market_context.enabled', false);
        $this->report($enabled ? self::PASS : self::WARN, 'Module flag', $enabled ? 'enabled' : 'AUTOFINX_MARKET_CONTEXT_ENABLED is off: the module collects and schedules nothing.');

        $missing = array_values(array_filter(self::TABLES, fn ($t) => ! Schema::hasTable($t)));
        $this->report($missing === [] ? self::PASS : self::FAIL, 'Tables present', $missing === [] ? null : 'missing: '.implode(', ', $missing).' (run the migration).');
        $this->configChecks($profiles);
        if ($missing !== []) {
            return $this->finish();
        }

        $this->schemaChecks();

        $this->section('Sources');
        $this->sourceChecks();

        $this->section('Point-in-time sanity');
        $this->pointInTimeChecks();

        $this->section('Snapshots');
        $this->snapshotChecks($profiles, $builder, $store);

        return $this->finish();
    }

    private function configChecks(ProfileRegistry $profiles): void
    {
        $sources = (array) config('market_context.sources', []);
        $bad = [];
        foreach ($sources as $id => $source) {
            if (! str_starts_with((string) ($source['url'] ?? ''), 'https://')) {
                $bad[] = $id;
            }
        }
        $this->report($bad === [] ? self::PASS : self::FAIL, 'Registry URLs are https', $bad === [] ? null : implode(', ', $bad));

        $unknown = [];
        foreach ((array) config('market_context.profiles', []) as $instrument => $profile) {
            foreach ((array) ($profile['collectors'] ?? []) as $collector) {
                if (! isset($sources[$collector])) {
                    $unknown[] = "$instrument:$collector";
                }
            }
        }
        $this->report($unknown === [] ? self::PASS : self::FAIL, 'Profile collectors exist in the registry', $unknown === [] ? null : implode(', ', $unknown));
        $this->report($profiles->enabled() !== [] ? self::PASS : self::WARN, 'At least one enabled profile');
    }

    private function schemaChecks(): void
    {
        $casts = collect([MarketContextEvent::class => 'first_seen_at', MarketContextEventInstrument::class => 'first_seen_at',
            MarketContextMetric::class => 'observed_at', MarketContextSnapshot::class => 'as_of', MarketContextSourceHealth::class => 'last_success_at'])
            ->filter(fn ($column, $model) => ((new $model())->getCasts()[$column] ?? null) !== UtcDateTime::class);
        $this->report($casts->isEmpty() ? self::PASS : self::FAIL, 'Instants are read as UTC (UtcDateTime cast)', $casts->isEmpty() ? null : $casts->keys()->implode(', '));

        $timestamps = [];
        foreach (self::TABLES as $table) {
            foreach (Schema::getColumns($table) as $column) {
                if (str_contains(strtolower((string) ($column['type_name'] ?? '')), 'timestamp')) {
                    $timestamps[] = $table.'.'.$column['name'];
                }
            }
        }
        $this->report($timestamps === [] ? self::PASS : self::FAIL, 'No TIMESTAMP columns (MariaDB ON UPDATE hazard)', $timestamps === [] ? null : implode(', ', $timestamps));
    }

    private function sourceChecks(): void
    {
        $now = now()->utc();
        foreach ((array) config('market_context.sources', []) as $id => $source) {
            if (! ($source['enabled'] ?? false)) {
                $this->report(self::PASS, $id, 'disabled in the registry (not fetched)');

                continue;
            }
            $health = MarketContextSourceHealth::query()->where('source', $id)->first();
            if ($health === null || $health->last_success_at === null) {
                $this->report(self::WARN, $id, $health === null ? 'never collected' : 'no successful fetch yet (last error '.($health->last_error_code ?? 'none').')');

                continue;
            }
            $ageMin = (int) floor(($now->getTimestamp() - $health->last_success_at->getTimestamp()) / 60);
            $limit = max(1, (int) ($source['refresh_minutes'] ?? 60)) * 3;
            if ($health->status === 'OPEN' || ($health->disabled_until && $health->disabled_until->greaterThan($now))) {
                $this->report(self::FAIL, $id, 'circuit breaker open after '.$health->consecutive_failures.' failures ('.($health->last_error_code ?? '?').')');
            } elseif ($health->status === 'FAILING' || $health->status === 'DEGRADED') {
                $this->report(self::WARN, $id, $health->status.' ('.($health->last_error_code ?? '?').'), last success '.$ageMin.' min ago');
            } elseif ($ageMin > $limit) {
                $this->report(self::WARN, $id, 'last success '.$ageMin.' min ago (expected every '.($source['refresh_minutes'] ?? 60).' min)');
            } else {
                $this->report(self::PASS, $id, 'last success '.$ageMin.' min ago');
            }
        }
    }

    private function pointInTimeChecks(): void
    {
        try {
            // Events are clamped to first_seen_at at ingestion; a later published_at would leak the future.
            $future = DB::table('mctx_events')->whereRaw('published_at > first_seen_at')->count();
            $this->report($future === 0 ? self::PASS : self::FAIL, 'No event published after it was first seen', $future === 0 ? null : "$future rows");
            $badMetrics = DB::table('mctx_metrics')->whereRaw('observed_at > first_seen_at')->count();
            $this->report($badMetrics === 0 ? self::PASS : self::FAIL, 'No metric observed after it was first seen', $badMetrics === 0 ? null : "$badMetrics rows");
            $orphans = DB::table('mctx_event_instruments')->whereNotIn('event_id', DB::table('mctx_events')->select('id'))->count();
            $this->report($orphans === 0 ? self::PASS : self::FAIL, 'No orphaned event links', $orphans === 0 ? null : "$orphans rows");
        } catch (Throwable $e) {
            $this->report(self::FAIL, 'Point-in-time queries', get_class($e));
        }
    }

    private function snapshotChecks(ProfileRegistry $profiles, SnapshotBuilder $builder, SnapshotStore $store): void
    {
        foreach ($profiles->enabled() as $instrument => $profile) {
            try {
                $live = $builder->build(new ContextQuery($profile['asset_class'], $instrument, now()->utc(), (int) config('market_context.snapshot.lookback_hours', 24) * 3600));
                $status = match ($live->freshness['state']) {
                    'fresh' => self::PASS,
                    default => self::WARN,
                };
                $this->report($status, $instrument.' live snapshot', 'freshness='.$live->freshness['state'].' risk='.$live->risk['level']);
            } catch (Throwable $e) {
                $this->report(self::FAIL, $instrument.' live snapshot', get_class($e));

                continue;
            }
            $stored = $store->latest($profile['asset_class'], $instrument);
            $limit = max(1, (int) config('market_context.snapshot.bucket_minutes', 15)) * 60 * 4;
            if ($stored === null) {
                $this->report(self::WARN, $instrument.' stored snapshot', 'none yet (market-context:snapshot)');
            } elseif (now()->getTimestamp() - $stored->as_of->getTimestamp() > $limit) {
                $this->report(self::WARN, $instrument.' stored snapshot', 'latest as_of '.$stored->as_of->toIso8601String().' is older than 4 buckets');
            } else {
                $this->report(self::PASS, $instrument.' stored snapshot', 'as_of '.$stored->as_of->toIso8601String());
            }
        }
    }

    private function finish(): int
    {
        $this->newLine();
        $this->line(sprintf('Summary: %d PASS, %d WARN, %d FAIL', $this->totals[self::PASS], $this->totals[self::WARN], $this->totals[self::FAIL]));

        return $this->totals[self::FAIL] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("== {$title}");
    }

    private function report(string $status, string $check, ?string $detail = null): void
    {
        $this->totals[$status]++;
        $label = match ($status) {
            self::PASS => '<fg=green>PASS</>',
            self::WARN => '<fg=yellow>WARN</>',
            default => '<fg=red>FAIL</>',
        };
        $this->line("  {$label}  {$check}".($detail !== null && $detail !== '' ? ": {$detail}" : ''));
    }
}
