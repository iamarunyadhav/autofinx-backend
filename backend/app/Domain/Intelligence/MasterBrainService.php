<?php

namespace App\Domain\Intelligence;

use App\Models\AiRecommendation;
use App\Models\Bot;
use App\Models\Order;
use App\Models\Trade;
use App\Models\TradeSignal;
use Illuminate\Support\Str;

class MasterBrainService
{
    public function mode(): string
    {
        $mode = strtoupper(trim((string) env('AI_MODE', 'ADVISORY')));

        return in_array($mode, ['OFF', 'ADVISORY', 'AUTO_TUNE'], true) ? $mode : 'ADVISORY';
    }

    public function recommend(Bot $bot, bool $autoApply = false): AiRecommendation
    {
        $stats = $this->buildStats($bot);
        $suggestion = $this->buildSuggestion($bot, $stats);
        $mode = $this->mode();

        $recommendation = AiRecommendation::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $bot->workspace_id,
            'bot_id' => $bot->id,
            'mode' => $mode,
            'applied' => false,
            'payload_json' => [
                'stats' => $stats,
                'suggestion' => $suggestion,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);

        if (($mode === 'AUTO_TUNE' && $autoApply) || $mode === 'AUTO_TUNE_FORCE') {
            $this->apply($bot, $recommendation);
        }

        return $recommendation->refresh();
    }

    public function apply(Bot $bot, AiRecommendation $recommendation): AiRecommendation
    {
        if ((string) $recommendation->bot_id !== (string) $bot->id) {
            throw new \RuntimeException('Recommendation does not belong to this bot.');
        }

        $suggestedParams = (array) data_get($recommendation->payload_json, 'suggestion.params', []);
        if (! empty($suggestedParams)) {
            $params = $bot->strategy_params ?? [];
            $bot->strategy_params = array_merge($params, $suggestedParams);
            $bot->save();
        }

        $recommendation->applied = true;
        $recommendation->applied_at = now();
        $recommendation->save();

        return $recommendation->refresh();
    }

    public function latestForBot(string $botId, int $limit = 20)
    {
        return AiRecommendation::where('bot_id', $botId)
            ->latest('created_at')
            ->limit(max(1, min(200, $limit)))
            ->get();
    }

    private function buildStats(Bot $bot): array
    {
        $trades = Trade::where('bot_id', $bot->id)
            ->latest('ts')
            ->limit(60)
            ->get();

        $orders = Order::where('bot_id', $bot->id)
            ->latest('placed_ts')
            ->limit(100)
            ->get();

        $signals = TradeSignal::where('bot_id', $bot->id)
            ->latest('decision_ts')
            ->limit(100)
            ->get();

        $tradeCount = $trades->count();
        $winCount = $trades->filter(fn (Trade $trade) => (float) $trade->realized_pnl > 0)->count();
        $lossCount = $trades->filter(fn (Trade $trade) => (float) $trade->realized_pnl < 0)->count();
        $winRate = $tradeCount > 0 ? ($winCount / $tradeCount) * 100 : 0.0;
        $avgPnl = $tradeCount > 0 ? (float) $trades->avg('realized_pnl') : 0.0;
        $avgSpread = $orders->count() > 0 ? (float) $orders->avg('spread_pct') : 0.0;
        $cancelRate = $signals->count() > 0
            ? ($signals->filter(fn (TradeSignal $signal) => strtoupper((string) $signal->status) === 'CANCELLED')->count() / $signals->count()) * 100
            : 0.0;

        $lossStreak = 0;
        foreach ($trades as $trade) {
            if ((float) $trade->realized_pnl < 0) {
                $lossStreak++;
                continue;
            }
            break;
        }

        return [
            'trade_count' => $tradeCount,
            'win_count' => $winCount,
            'loss_count' => $lossCount,
            'win_rate_pct' => round($winRate, 3),
            'avg_realized_pnl' => round($avgPnl, 8),
            'avg_spread_pct' => round($avgSpread, 4),
            'signal_cancel_rate_pct' => round($cancelRate, 3),
            'loss_streak' => $lossStreak,
        ];
    }

    private function buildSuggestion(Bot $bot, array $stats): array
    {
        $params = $bot->strategy_params ?? [];
        $buyDrop = (float) ($params['buy_drop_pct'] ?? 1.0);
        $sellRise = (float) ($params['sell_rise_pct'] ?? 1.0);
        $minProfit = (float) ($params['min_profit_after_fees_pct'] ?? 0.25);
        $coolDown = (int) ($params['cool_down_sec'] ?? 45);

        $winRate = (float) ($stats['win_rate_pct'] ?? 0);
        $avgPnl = (float) ($stats['avg_realized_pnl'] ?? 0);
        $lossStreak = (int) ($stats['loss_streak'] ?? 0);
        $cancelRate = (float) ($stats['signal_cancel_rate_pct'] ?? 0);

        $posture = 'neutral';
        $reason = 'Performance neutral. Keep current parameters.';
        $next = [
            'buy_drop_pct' => $buyDrop,
            'sell_rise_pct' => $sellRise,
            'min_profit_after_fees_pct' => $minProfit,
            'cool_down_sec' => $coolDown,
        ];

        if ($lossStreak >= 3 || $winRate < 45 || $avgPnl < 0) {
            $posture = 'tighten';
            $reason = 'Loss streak / low win-rate detected. Tighten entry and risk pacing.';
            $next['buy_drop_pct'] = round($buyDrop + 0.2, 4);
            $next['sell_rise_pct'] = round($sellRise + 0.2, 4);
            $next['min_profit_after_fees_pct'] = round($minProfit + 0.1, 4);
            $next['cool_down_sec'] = min(900, $coolDown + 20);
        } elseif ($winRate >= 65 && $avgPnl > 0 && $cancelRate < 60) {
            $posture = 'loosen';
            $reason = 'Strong win-rate and healthy fills. Small loosening recommended.';
            $next['buy_drop_pct'] = max(0.1, round($buyDrop - 0.1, 4));
            $next['sell_rise_pct'] = max(0.1, round($sellRise - 0.1, 4));
            $next['min_profit_after_fees_pct'] = max(0.05, round($minProfit - 0.05, 4));
            $next['cool_down_sec'] = max(5, $coolDown - 10);
        }

        return [
            'posture' => $posture,
            'reason' => $reason,
            'params' => $next,
            'can_auto_apply' => $this->mode() === 'AUTO_TUNE',
        ];
    }
}
