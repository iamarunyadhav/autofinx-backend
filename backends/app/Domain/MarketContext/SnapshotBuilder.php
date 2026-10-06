<?php

namespace App\Domain\MarketContext;

use App\Domain\MarketContext\Data\ContextQuery;
use App\Domain\MarketContext\Data\ContextSnapshot;
use App\Domain\MarketContext\Support\AssetClass;
use App\Domain\MarketContext\Support\ProfileRegistry;
use App\Domain\MarketContext\Support\UtcTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rules-only snapshot (Phase 1). A pure function of the stored rows and
 * $query->asOf: it never calls now(), and only rows with first_seen_at <= asOf
 * are read, so a backtest reproduces the live value exactly. Missing data is
 * reported as 'unavailable', never as a neutral default. Output is advisory.
 */
final class SnapshotBuilder
{
    public function __construct(private readonly ProfileRegistry $profiles) {}

    public function build(ContextQuery $query, ?Carbon $computedAt = null): ContextSnapshot
    {
        $profile = $this->profiles->find($query->assetClass, $query->instrument);
        if ($profile === null) {
            throw new InvalidArgumentException('Unknown instrument profile.');
        }
        $instrument = strtoupper($query->instrument);
        $asOf = $query->asOf;

        [$drivers, $contributions, $classifierVersions] = $this->events($query);
        $metrics = $this->metrics($query);
        $freshness = $this->freshness($query, (array) ($profile['collectors'] ?? []));

        return new ContextSnapshot($instrument, strtoupper($query->assetClass), $asOf, (int) config('market_context.schema_version', 1),
            $freshness, $this->sentiment($metrics), $this->risk($freshness, $contributions, $drivers, $metrics),
            $this->publicMetrics($metrics), array_slice($drivers, 0, (int) config('market_context.limits.snapshot_top_drivers', 5)),
            ['classifier_versions' => $classifierVersions, 'snapshot_computed_at' => ($computedAt ?? $asOf)->copy()->utc()->toIso8601String()]);
    }

    /** @return array{0: list<array>, 1: list<float>, 2: list<string>} drivers (best first), contributions, classifier versions */
    private function events(ContextQuery $query): array
    {
        $asOf = $query->asOf;
        $rows = DB::table('mctx_event_instruments as ei')
            ->join('mctx_events as e', 'e.id', '=', 'ei.event_id')
            ->where('ei.asset_class', strtoupper($query->assetClass))
            ->where('ei.instrument', strtoupper($query->instrument))
            ->where('ei.first_seen_at', '<=', $asOf->copy()->utc())
            ->where('e.first_seen_at', '<=', $asOf->copy()->utc())
            ->where('ei.published_at', '>=', $asOf->copy()->subSeconds($query->lookbackSeconds)->utc())
            ->orderByDesc('e.published_at')->orderBy('e.id')
            ->limit((int) config('market_context.limits.snapshot_max_events', 400))
            ->get(['e.id', 'e.title', 'e.category', 'e.severity', 'e.url', 'e.source', 'e.published_at', 'e.classifier_version', 'ei.relevance']);

        $risk = (array) config('market_context.risk');
        $min = (float) $risk['min_contribution'];
        $drivers = [];
        $versions = [];
        foreach ($rows as $row) {
            $published = UtcTime::fromDb($row->published_at);
            $ageHours = max(0.0, ($asOf->getTimestamp() - $published->getTimestamp()) / 3600);
            $half = max(0.1, (float) ($risk['half_life_hours'][$row->category] ?? $risk['half_life_hours']['default']));
            $contribution = (float) ($risk['severity_prior'][$row->severity] ?? 0.0)
                * (float) ($risk['category_multiplier'][$row->category] ?? 0.4)
                * (float) $row->relevance * (0.5 ** ($ageHours / $half));
            if ($contribution < $min) {
                continue;
            }
            $drivers[] = ['event_id' => $row->id, 'title' => $row->title, 'category' => $row->category, 'severity' => $row->severity,
                'impact' => round($contribution, 4), 'published_at' => $published->toIso8601String(), 'source' => $row->source, 'url' => $row->url];
            if ($row->classifier_version) {
                $versions[$row->classifier_version] = true;
            }
        }
        usort($drivers, fn ($a, $b) => [$b['impact'], $a['event_id']] <=> [$a['impact'], $b['event_id']]);
        ksort($versions);

        return [$drivers, array_column($drivers, 'impact'), array_keys($versions)];
    }

    /** @return array<string, array{value: float, observed_at: Carbon}> latest value per metric, plus derived change */
    private function metrics(ContextQuery $query): array
    {
        $asOf = $query->asOf;
        $cfg = (array) config('market_context.snapshot');
        $rows = DB::table('mctx_metrics')
            ->where('asset_class', strtoupper($query->assetClass))
            ->whereIn('instrument', [strtoupper($query->instrument), AssetClass::MARKET_WIDE])
            ->where('first_seen_at', '<=', $asOf->copy()->utc())
            ->where('observed_at', '<=', $asOf->copy()->utc())
            ->where('observed_at', '>=', $asOf->copy()->subHours((int) $cfg['metrics_lookback_hours'])->utc())
            ->orderByDesc('observed_at')->orderBy('id')
            ->limit((int) config('market_context.limits.snapshot_max_metric_rows', 800))
            ->get(['metric', 'value', 'observed_at']);

        $series = [];
        foreach ($rows as $row) {
            $series[$row->metric][] = ['value' => (float) $row->value, 'observed_at' => UtcTime::fromDb($row->observed_at)];
        }
        $latest = [];
        foreach ($series as $metric => $points) {
            $latest[$metric] = $points[0];
        }
        if (isset($series['open_interest_usd'])) {
            $window = (int) $cfg['oi_change_window_hours'] * 3600;
            $cut = $series['open_interest_usd'][0]['observed_at']->getTimestamp() - $window;
            foreach ($series['open_interest_usd'] as $point) {
                if ($point['observed_at']->getTimestamp() <= $cut) {
                    if ($point['value'] > 0) {
                        $latest['open_interest_change_pct'] = ['value' => ($series['open_interest_usd'][0]['value'] - $point['value']) / $point['value'] * 100,
                            'observed_at' => $series['open_interest_usd'][0]['observed_at']];
                    }
                    break;
                }
            }
        }

        return $latest;
    }

    private function publicMetrics(array $metrics): array
    {
        $out = [];
        foreach (['fear_greed', 'open_interest_usd', 'open_interest_change_pct', 'long_short_ratio', 'taker_buy_sell_ratio'] as $name) {
            if (isset($metrics[$name])) {
                $out[$name] = round($metrics[$name]['value'], 6);
            }
        }

        return $out;
    }

    private function sentiment(array $metrics): array
    {
        if (! isset($metrics['fear_greed'])) {
            return ['score' => null, 'confidence' => 0.0, 'n_items' => 0, 'method' => 'none'];
        }

        // A market-wide index, not an asset-specific read: low confidence by design.
        return ['score' => round(($metrics['fear_greed']['value'] - 50) / 50, 4), 'confidence' => 0.3, 'n_items' => 1, 'method' => 'fear_greed_index'];
    }

    private function freshness(ContextQuery $query, array $collectors): array
    {
        $asOf = $query->asOf;
        $registry = (array) config('market_context.sources');
        $sources = array_values(array_filter($collectors, fn ($id) => ($registry[$id]['enabled'] ?? false)));
        $floor = $asOf->copy()->subDays(14)->utc();
        $newest = [];
        $eventIds = array_values(array_filter($sources, fn ($id) => ($registry[$id]['kind'] ?? '') === 'event'));
        $metricIds = array_values(array_filter($sources, fn ($id) => ($registry[$id]['kind'] ?? '') === 'metric'));
        if ($eventIds !== []) {
            foreach (DB::table('mctx_events')->whereIn('source', $eventIds)->where('first_seen_at', '<=', $asOf->copy()->utc())
                ->where('first_seen_at', '>=', $floor)->groupBy('source')->selectRaw('source, MAX(first_seen_at) AS seen')->get() as $row) {
                $newest[$row->source] = UtcTime::fromDb($row->seen);
            }
        }
        if ($metricIds !== []) {
            foreach (DB::table('mctx_metrics')->whereIn('source', $metricIds)->where('first_seen_at', '<=', $asOf->copy()->utc())
                ->where('first_seen_at', '>=', $floor)->whereIn('instrument', [strtoupper($query->instrument), AssetClass::MARKET_WIDE])
                ->groupBy('source')->selectRaw('source, MAX(first_seen_at) AS seen')->get() as $row) {
                $newest[$row->source] = UtcTime::fromDb($row->seen);
            }
        }

        $list = [];
        $allFresh = true;
        $any = false;
        $youngest = null;
        foreach ($sources as $id) {
            if (! isset($newest[$id])) {
                $list[] = ['id' => $id, 'status' => 'unavailable', 'age_s' => null];
                $allFresh = false;

                continue;
            }
            $age = max(0, $asOf->getTimestamp() - $newest[$id]->getTimestamp());
            $fresh = $age <= (int) ($registry[$id]['stale_after_minutes'] ?? 60) * 60;
            $list[] = ['id' => $id, 'status' => $fresh ? 'fresh' : 'stale', 'age_s' => $age];
            $allFresh = $allFresh && $fresh;
            $any = true;
            $youngest = $youngest === null ? $age : min($youngest, $age);
        }

        return ['state' => ! $any ? 'unavailable' : ($allFresh ? 'fresh' : 'stale'), 'newest_item_age_s' => $youngest, 'sources' => $list];
    }

    private function risk(array $freshness, array $contributions, array $drivers, array $metrics): array
    {
        if ($freshness['state'] === 'unavailable') {
            return ['level' => 'UNAVAILABLE', 'flags' => [], 'veto_hint' => false, 'reasons' => ['No market context data has been collected for this instrument.']];
        }
        $cfg = (array) config('market_context.risk');
        $keep = 1.0;
        foreach ($contributions as $c) {
            $keep *= (1.0 - min(0.99, $c));
        }
        $score = 1.0 - $keep;
        $level = 'LOW';
        foreach (['MEDIUM', 'HIGH', 'CRITICAL'] as $name) {
            if ($score >= (float) $cfg['levels'][$name]) {
                $level = $name;
            }
        }

        $flags = [];
        $reasons = [];
        foreach (array_slice($drivers, 0, 3) as $d) {
            $flags[] = $d['category'];
            $reasons[] = sprintf('%s %s: %s', $d['severity'], $d['category'], mb_substr($d['title'], 0, 120));
        }
        if (isset($metrics['fear_greed'])) {
            if ($metrics['fear_greed']['value'] <= (float) $cfg['fear_greed_extreme_low']) {
                $flags[] = 'EXTREME_FEAR';
                $reasons[] = 'Fear & Greed index at extreme fear.';
            } elseif ($metrics['fear_greed']['value'] >= (float) $cfg['fear_greed_extreme_high']) {
                $flags[] = 'EXTREME_GREED';
                $reasons[] = 'Fear & Greed index at extreme greed.';
            }
        }
        if (isset($metrics['open_interest_change_pct']) && abs($metrics['open_interest_change_pct']['value']) >= (float) $cfg['oi_change_flag_pct']) {
            $flags[] = 'OPEN_INTEREST_SHIFT';
            $reasons[] = sprintf('Open interest moved %+.1f%% over the window.', $metrics['open_interest_change_pct']['value']);
        }
        if ($freshness['state'] === 'stale') {
            $flags[] = 'STALE_CONTEXT';
            $reasons[] = 'At least one source is stale or has no data; the read may be incomplete.';
        }

        return ['level' => $level, 'flags' => array_values(array_unique($flags)), 'veto_hint' => in_array($level, ['HIGH', 'CRITICAL'], true), 'reasons' => $reasons];
    }
}
