<?php

namespace App\Domain\FutureTrade;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class FutureTradeBotService
{
    public function __construct(
        private readonly LivePortfolioGuardService $liveGuard,
    ) {}

    public function getOrCreateConfig(string $workspaceId): FutureTradeBotConfig
    {
        $config = FutureTradeBotConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'bot_name' => 'Future Trade Bot',
                'paper_trading' => true,
                'enable_live_trading' => false,
                'enabled' => false,
                'settings' => config('future_trade.defaults'),
                'algorithm_notes' => "Video-style futures scanner: scan symbols, issue BUY/SELL signals, paper execute only, protect with stop loss, rollback and trailing profit.",
            ]
        );

        $config->state()->firstOrCreate([], [
            'status' => 'Stopped',
            'state' => 'IDLE',
            'connection_status' => 'Disconnected',
        ]);

        return $config->fresh('state');
    }

    public function updateConfig(FutureTradeBotConfig $config, array $payload): FutureTradeBotConfig
    {
        $settings = array_merge($config->mergedSettings(), (array) ($payload['settings'] ?? []));
        $liveRequested = (bool) ($settings['liveTrading'] ?? false);
        $liveAllowed = $liveRequested && (bool) config('future_trade.enable_live_trading');

        if ($liveAllowed) {
            $guard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
            if (! $guard['allowed']) {
                $liveAllowed = false;
            }
        }

        $settings['liveTrading'] = $liveAllowed;
        $settings['paperTrading'] = ! $liveAllowed;
        $settings['forceTestnet'] = array_key_exists('forceTestnet', $settings)
            ? (bool) $settings['forceTestnet']
            : (bool) config('future_trade.force_testnet');

        $config->update([
            'paper_trading' => ! $liveAllowed,
            'enable_live_trading' => $liveAllowed,
            'settings' => $settings,
            'algorithm_notes' => $payload['algorithm_notes'] ?? $config->algorithm_notes,
        ]);

        if ($liveRequested && ! $liveAllowed) {
            $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', null, null, 0, 'Another live bot is already active for this workspace/account. Future Trade stays in paper mode.');
        }

        $this->log($config->fresh('state'), 'CONFIG_UPDATED', null, null, 0, $liveAllowed ? 'Future Trade Bot config saved. Live futures testnet enabled.' : 'Future Trade Bot config saved. Paper trading active.');
        return $config->fresh('state');
    }

    public function start(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $config->update(['enabled' => true, 'paper_trading' => true, 'enable_live_trading' => false]);
        if ((bool) ($config->mergedSettings()['liveTrading'] ?? false) && (bool) config('future_trade.enable_live_trading')) {
            $guard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
            if ($guard['allowed']) {
                $config->update(['paper_trading' => false, 'enable_live_trading' => true]);
            } else {
                $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', null, null, 0, $guard['reason']);
            }
        }
        $state = $this->state($config);
        $state->update([
            'status' => 'Running',
            'state' => 'SCANNING',
            'connection_status' => 'Connected',
            'current_signal' => 'WAITING_FOR_SIGNAL',
        ]);
        $this->log($config->fresh('state'), 'BOT_STARTED', null, null, 0, 'Futures paper scanner started.');
        $this->scan($config->fresh('state'), true);
        return $state->fresh();
    }

    public function stop(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $config->update(['enabled' => false]);
        $state = $this->state($config);
        $state->update([
            'status' => 'Stopped',
            'state' => 'STOPPED',
            'connection_status' => 'Disconnected',
            'current_signal' => 'WAITING_FOR_SIGNAL',
        ]);
        $this->log($config->fresh('state'), 'BOT_STOPPED', $state->current_symbol, null, 0, 'Operator stopped futures bot.');
        return $state->fresh();
    }

    public function emergencyStop(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $settings = $config->mergedSettings();
        $settings['emergencyStop'] = true;
        if ((float) ($this->state($config)->quantity ?? 0) > 0 && $config->enable_live_trading) {
            try {
                $this->closeLivePosition($config, (float) ($this->state($config)->current_price ?? 0), 'EMERGENCY_STOP');
            } catch (Throwable $e) {
                $this->log($config->fresh('state'), 'LIVE_EMERGENCY_CLOSE_FAILED', $config->state?->current_symbol, null, 0, $e->getMessage());
            }
        }
        $config->update(['enabled' => false, 'settings' => $settings]);
        $state = $this->state($config);
        $state->update([
            'status' => 'Stopped',
            'state' => 'EMERGENCY_STOP',
            'connection_status' => 'Disconnected',
            'current_signal' => 'EMERGENCY_STOP',
            'quantity' => 0,
            'position_side' => null,
        ]);
        $this->log($config->fresh('state'), 'EMERGENCY_STOP', $state->current_symbol, null, 0, 'All paper futures activity halted.');
        return $state->fresh();
    }

    public function resetPaper(FutureTradeBotConfig $config): FutureTradeBotState
    {
        $config->trades()->delete();
        $state = $this->state($config);
        $state->update([
            'state' => 'IDLE',
            'current_symbol' => null,
            'current_signal' => 'WAITING_FOR_SIGNAL',
            'entry_price' => null,
            'quantity' => 0,
            'position_side' => null,
            'peak_price' => null,
            'bottom_price' => null,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => 0,
            'daily_profit' => 0,
            'daily_loss' => 0,
            'trade_count_today' => 0,
            'wins' => 0,
            'losses' => 0,
            'scanner_snapshot' => [],
            'chart_points' => [],
        ]);
        $this->log($config->fresh('state'), 'PAPER_RESET', null, null, 0, 'Paper futures ledger reset.');
        return $state->fresh();
    }

    public function scan(FutureTradeBotConfig $config, bool $allowPaperExecution = true): array
    {
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $market = $this->marketSnapshot($settings);
        $signals = array_map(fn (array $ticker) => $this->scoreTicker($ticker, $settings), $market);
        usort($signals, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
        $best = $signals[0] ?? null;

        if (! $best) {
            return ['signals' => [], 'bestSignal' => null];
        }

        $state->refresh();
        $inPosition = (float) $state->quantity > 0;

        if ($inPosition) {
            // When holding a position, track the price of THAT symbol, not the best signal's symbol
            $positionSymbol = (string) $state->current_symbol;
            $positionTicker = collect($market)->first(fn ($row) => (string) ($row['symbol'] ?? '') === $positionSymbol);
            $positionPrice = $positionTicker ? (float) ($positionTicker['lastPrice'] ?? $positionTicker['price'] ?? 0) : 0;

            if ($positionPrice > 0) {
                $chart = $this->appendChartPoint($state, $positionSymbol, $positionPrice);
                $state->update([
                    'connection_status' => 'Connected',
                    'current_price' => $positionPrice,
                    'last_scan_at' => now(),
                    'scanner_snapshot' => array_slice($signals, 0, 12),
                    'chart_points' => $chart,
                ]);
                $this->manageOpenPosition($config->fresh('state'), $positionPrice);
            }
        } else {
            $chart = $this->appendChartPoint($state, $best['symbol'], (float) $best['price']);
            $state->update([
                'connection_status' => 'Connected',
                'current_symbol' => $best['symbol'],
                'current_signal' => $best['signal'],
                'current_price' => $best['price'],
                'confidence' => $best['confidence'],
                'last_scan_at' => now(),
                'scanner_snapshot' => array_slice($signals, 0, 12),
                'chart_points' => $chart,
            ]);

            if ($allowPaperExecution && $config->enabled && ($settings['autoExecutePaperSignals'] ?? true)) {
                $this->maybeEnterPaperPosition($config->fresh('state'), $best);
            }
        }

        return ['signals' => array_slice($signals, 0, 20), 'bestSignal' => $best];
    }

    public function statusPayload(FutureTradeBotConfig $config): array
    {
        if ($config->enabled) {
            $this->scan($config, true);
            $config = $config->fresh('state');
        }

        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $wins = (int) $state->wins;
        $losses = (int) $state->losses;
        $total = max(1, $wins + $losses);
        $accountSummary = $this->accountSummary($config);

        return [
            'botName' => 'Future Trade Bot',
            'status' => $state->status,
            'state' => $state->state,
            'mode' => $this->isLiveMode($config) ? (($settings['forceTestnet'] ?? true) ? 'Live Testnet Futures' : 'Live Futures') : 'Paper Futures',
            'exchange' => 'Binance Futures',
            'connectionStatus' => $state->connection_status,
            'currentSymbol' => $state->current_symbol,
            'currentSignal' => $state->current_signal,
            'currentPrice' => (float) ($state->current_price ?? 0),
            'confidence' => (float) $state->confidence,
            'positionSide' => $state->position_side,
            'entryPrice' => (float) ($state->entry_price ?? 0),
            'quantity' => (float) $state->quantity,
            'unrealizedPnl' => (float) $state->unrealized_pnl,
            'unrealizedPnlPercent' => (float) $state->unrealized_pnl_percent,
            'realizedPnl' => (float) $state->realized_pnl,
            'dailyProfit' => (float) $state->daily_profit,
            'dailyLoss' => (float) $state->daily_loss,
            'winRate' => round(($wins / $total) * 100, 2),
            'tradeCountToday' => (int) $state->trade_count_today,
            'lastScanAt' => optional($state->last_scan_at)->toIso8601String(),
            'settings' => $settings,
            'paperTrading' => ! $this->isLiveMode($config),
            'liveTrading' => $this->isLiveMode($config),
            'liveTradingEnvEnabled' => (bool) config('future_trade.enable_live_trading'),
            'liveGuard' => $this->liveGuard->canActivate($config->workspace_id, 'future_trade'),
            'walletBalance' => $accountSummary['walletBalance'],
            'availableBalance' => $accountSummary['availableBalance'],
            'marginBalance' => $accountSummary['marginBalance'],
            'botProfit' => $accountSummary['botProfit'],
            'botLoss' => $accountSummary['botLoss'],
            'totalProfit' => $accountSummary['totalProfit'],
            'balanceSource' => $accountSummary['balanceSource'],
            'accountAsset' => $accountSummary['accountAsset'],
        ];
    }

    public function dashboardPayload(FutureTradeBotConfig $config): array
    {
        $scan = $this->scan($config, $config->enabled);
        $config = $config->fresh('state');
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $entry = (float) ($state->entry_price ?? 0);
        $peak = (float) ($state->peak_price ?? 0);

        return [
            'status' => $this->statusPayload($config),
            'scanner' => $scan['signals'],
            'bestSignal' => $scan['bestSignal'],
            'chart' => $state->chart_points ?? [],
            'levels' => [
                'entry' => $entry ?: null,
                'stopLoss' => $entry ? $this->stopLossPrice($entry, (string) $state->position_side, $settings) : null,
                'takeProfit' => $entry ? $this->takeProfitPrice($entry, (string) $state->position_side, $settings) : null,
                'trailing' => $entry && $peak ? $this->trailingPrice($peak, (string) $state->position_side, $settings) : null,
                'peak' => $peak ?: null,
                'bottom' => (float) ($state->bottom_price ?? 0) ?: null,
            ],
            'orderbook' => $this->syntheticOrderbook((float) ($state->current_price ?? 0)),
            'risk' => [
                'lossPrevention' => 'Stop loss, rollback, trailing profit, cooldown, max daily loss and max trades are active in paper mode.',
                'paperTrading' => ! $this->isLiveMode($config),
                'liveTradingBlocked' => ! (bool) config('future_trade.enable_live_trading'),
            ],
        ];
    }

    public function configPayload(FutureTradeBotConfig $config): array
    {
        return [
            'id' => $config->id,
            'settings' => $config->mergedSettings(),
            'algorithm_notes' => $config->algorithm_notes,
            'paper_trading' => $config->paper_trading,
            'enable_live_trading' => $config->enable_live_trading,
            'live_env_enabled' => (bool) config('future_trade.enable_live_trading'),
            'available_symbols' => config('future_trade.available_symbols', []),
        ];
    }

    private function maybeEnterPaperPosition(FutureTradeBotConfig $config, array $signal): void
    {
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        if ((string) $state->state === 'IN_POSITION' || (float) $state->quantity > 0) {
            return;
        }
        if (($settings['emergencyStop'] ?? false) || $signal['signal'] === 'WAIT') {
            return;
        }
        if ((float) $signal['confidence'] < (float) ($settings['minConfidence'] ?? 0.68)) {
            $this->log($config, 'SIGNAL_SKIPPED', $signal['symbol'], $signal['signal'], $signal['confidence'], 'Confidence below threshold.');
            return;
        }
        if ($state->cooldown_until && $state->cooldown_until->isFuture()) {
            return;
        }
        if ((int) $state->trade_count_today >= (int) ($settings['maxTradesPerDay'] ?? 25)) {
            $this->log($config, 'TRADE_BLOCKED', $signal['symbol'], $signal['signal'], $signal['confidence'], 'Max trades per day reached.');
            return;
        }
        if ((float) $state->daily_loss >= (float) ($settings['maxDailyLossPercent'] ?? 2)) {
            $this->log($config, 'TRADE_BLOCKED', $signal['symbol'], $signal['signal'], $signal['confidence'], 'Daily loss guard active.');
            return;
        }

        if ($this->isLiveMode($config)) {
            $this->openLivePosition($config, $signal);
            return;
        }

        $side = $signal['signal'] === 'BUY' ? 'LONG' : 'SHORT';
        $price = (float) $signal['price'];
        $qty = ((float) ($settings['tradeAmountUsdt'] ?? 25) * (float) ($settings['leverage'] ?? 3)) / max($price, 0.00000001);
        $state->update([
            'state' => 'IN_POSITION',
            'current_symbol' => $signal['symbol'],
            'position_side' => $side,
            'entry_price' => $price,
            'quantity' => $qty,
            'peak_price' => $price,
            'bottom_price' => $price,
            'current_signal' => $signal['signal'] . '_EXECUTED',
            'trade_count_today' => (int) $state->trade_count_today + 1,
        ]);

        $config->trades()->create([
            'symbol' => $signal['symbol'],
            'side' => $side,
            'entry_price' => $price,
            'quantity' => $qty,
            'leverage' => (float) ($settings['leverage'] ?? 3),
            'fees' => $price * $qty * 0.0004,
            'reason' => 'AUTO_' . $signal['signal'] . '_SIGNAL',
            'mode' => 'PAPER',
            'status' => 'OPEN',
            'meta' => ['signal' => $signal, 'idempotency_key' => (string) Str::uuid()],
        ]);
        $this->log($config->fresh('state'), $signal['signal'] . '_EXECUTED', $signal['symbol'], $signal['signal'], $signal['confidence'], 'Paper futures position opened.');
    }

    private function manageOpenPosition(FutureTradeBotConfig $config, float $price): void
    {
        $state = $this->state($config);
        $settings = $config->mergedSettings();
        $entry = (float) $state->entry_price;
        $qty = (float) $state->quantity;
        $side = (string) $state->position_side;
        if ($entry <= 0 || $qty <= 0 || ! in_array($side, ['LONG', 'SHORT'], true)) {
            return;
        }

        $direction = $side === 'LONG' ? 1 : -1;
        $gross = ($price - $entry) * $qty * $direction;
        $fees = $price * $qty * 0.0004;
        $net = $gross - $fees;
        $notional = $entry * $qty;
        $pct = $notional > 0 ? ($net / $notional) * 100 : 0;
        $peak = $side === 'LONG' ? max((float) ($state->peak_price ?? $entry), $price) : min((float) ($state->peak_price ?? $entry), $price);
        $bottom = $side === 'LONG' ? min((float) ($state->bottom_price ?? $entry), $price) : max((float) ($state->bottom_price ?? $entry), $price);

        $state->update([
            'current_price' => $price,
            'peak_price' => $peak,
            'bottom_price' => $bottom,
            'unrealized_pnl' => $net,
            'unrealized_pnl_percent' => $pct,
        ]);

        $reason = null;
        if ($pct >= (float) ($settings['takeProfitPercent'] ?? 0.45)) {
            $reason = 'TAKE_PROFIT_HIT';
        } elseif ($pct <= -abs((float) ($settings['rollbackLossPercent'] ?? 0.18))) {
            $reason = 'LOSS_ROLLBACK_TRIGGERED';
        } elseif (($side === 'LONG' && $price <= $this->stopLossPrice($entry, $side, $settings)) || ($side === 'SHORT' && $price >= $this->stopLossPrice($entry, $side, $settings))) {
            $reason = 'STOP_LOSS_HIT';
        } elseif (($side === 'LONG' && $price <= $this->trailingPrice($peak, $side, $settings) && $peak > $entry) || ($side === 'SHORT' && $price >= $this->trailingPrice($peak, $side, $settings) && $peak < $entry)) {
            $reason = 'TRAILING_PROFIT_HIT';
        }

        if ($reason) {
            if ($this->isLiveMode($config)) {
                $this->closeLivePosition($config->fresh('state'), $price, $reason);
            } else {
                $this->closePaperPosition($config->fresh('state'), $price, $reason);
            }
        }
    }

    private function openLivePosition(FutureTradeBotConfig $config, array $signal): void
    {
        $guard = $this->liveGuard->canActivate($config->workspace_id, 'future_trade');
        if (! $guard['allowed']) {
            $this->log($config->fresh('state'), 'LIVE_GUARD_BLOCKED', (string) $signal['symbol'], (string) $signal['signal'], (float) $signal['confidence'], $guard['reason']);
            return;
        }

        $settings = $config->mergedSettings();
        $client = $this->liveClient($settings);
        $symbol = strtoupper((string) $signal['symbol']);
        $side = $signal['signal'] === 'BUY' ? 'BUY' : 'SELL';
        $positionSide = $signal['signal'] === 'BUY' ? 'LONG' : 'SHORT';
        $price = (float) $signal['price'];
        $leverage = min((int) ($settings['leverage'] ?? 3), (int) ($settings['maxLeverage'] ?? 5));
        $filters = $this->symbolFilters($client->fetchExchangeInfo($symbol), $symbol);
        $qty = ((float) ($settings['tradeAmountUsdt'] ?? 25) * $leverage) / max($price, 0.00000001);
        $qty = $this->formatStep($qty, $filters['stepSize']);

        $client->setLeverage($symbol, $leverage);
        $client->setMarginType($symbol, (string) ($settings['marginMode'] ?? 'isolated'));
        $response = $client->placeOrder([
            'symbol' => $symbol,
            'side' => $side,
            'type' => 'MARKET',
            'quantity' => $qty,
            'newClientOrderId' => substr('ft-entry-' . Str::uuid(), 0, 36),
            'positionSide' => 'BOTH',
        ]);

        $entry = (float) ($response['avgPrice'] ?? 0) ?: $price;
        $stop = $this->stopLossPrice($entry, $positionSide, $settings);
        $take = $this->takeProfitPrice($entry, $positionSide, $settings);
        $exitSide = $side === 'BUY' ? 'SELL' : 'BUY';
        $stopResponse = $client->placeOrder([
            'symbol' => $symbol,
            'side' => $exitSide,
            'type' => 'STOP_MARKET',
            'stopPrice' => $this->formatStep($stop, $filters['tickSize']),
            'quantity' => $qty,
            'reduceOnly' => 'true',
            'workingType' => 'MARK_PRICE',
            'newClientOrderId' => substr('ft-sl-' . Str::uuid(), 0, 36),
            'positionSide' => 'BOTH',
        ]);
        $takeResponse = $client->placeOrder([
            'symbol' => $symbol,
            'side' => $exitSide,
            'type' => 'TAKE_PROFIT_MARKET',
            'stopPrice' => $this->formatStep($take, $filters['tickSize']),
            'quantity' => $qty,
            'reduceOnly' => 'true',
            'workingType' => 'MARK_PRICE',
            'newClientOrderId' => substr('ft-tp-' . Str::uuid(), 0, 36),
            'positionSide' => 'BOTH',
        ]);

        $state = $this->state($config);
        $state->update([
            'state' => 'LIVE_IN_POSITION',
            'current_symbol' => $symbol,
            'position_side' => $positionSide,
            'entry_price' => $entry,
            'quantity' => (float) $qty,
            'peak_price' => $entry,
            'bottom_price' => $entry,
            'current_signal' => $signal['signal'] . '_LIVE_EXECUTED',
            'trade_count_today' => (int) $state->trade_count_today + 1,
        ]);

        $config->trades()->create([
            'symbol' => $symbol,
            'side' => $positionSide,
            'entry_price' => $entry,
            'quantity' => (float) $qty,
            'leverage' => $leverage,
            'fees' => $entry * (float) $qty * 0.0004,
            'reason' => 'LIVE_' . $signal['signal'] . '_SIGNAL',
            'mode' => $settings['forceTestnet'] ?? true ? 'LIVE_TESTNET' : 'LIVE',
            'status' => 'OPEN',
            'meta' => ['entry_order' => $response, 'stop_order' => $stopResponse, 'take_profit_order' => $takeResponse, 'signal' => $signal],
        ]);
        $this->log($config->fresh('state'), 'LIVE_' . $signal['signal'] . '_EXECUTED', $symbol, $signal['signal'], (float) $signal['confidence'], 'Live futures entry submitted with protective reduce-only exits.');
    }

    private function closeLivePosition(FutureTradeBotConfig $config, float $price, string $reason): void
    {
        $state = $this->state($config);
        if ((float) $state->quantity <= 0 || ! $state->current_symbol || ! $state->position_side) {
            return;
        }

        $settings = $config->mergedSettings();
        $client = $this->liveClient($settings);
        $side = $state->position_side === 'LONG' ? 'SELL' : 'BUY';
        $filters = $this->symbolFilters($client->fetchExchangeInfo((string) $state->current_symbol), (string) $state->current_symbol);
        $qty = $this->formatStep((float) $state->quantity, $filters['stepSize']);
        $response = $client->placeOrder([
            'symbol' => (string) $state->current_symbol,
            'side' => $side,
            'type' => 'MARKET',
            'quantity' => $qty,
            'reduceOnly' => 'true',
            'newClientOrderId' => substr('ft-close-' . Str::uuid(), 0, 36),
            'positionSide' => 'BOTH',
        ]);

        $direction = $state->position_side === 'LONG' ? 1 : -1;
        $entry = (float) $state->entry_price;
        $gross = ($price - $entry) * (float) $state->quantity * $direction;
        $fees = $price * (float) $state->quantity * 0.0004;
        $net = $gross - $fees;

        $config->trades()->create([
            'symbol' => (string) $state->current_symbol,
            'side' => 'LIVE_CLOSE_' . $state->position_side,
            'entry_price' => $entry,
            'exit_price' => $price,
            'quantity' => (float) $state->quantity,
            'leverage' => (float) ($settings['leverage'] ?? 3),
            'gross_pnl' => $gross,
            'fees' => $fees,
            'net_pnl' => $net,
            'reason' => $reason,
            'mode' => $settings['forceTestnet'] ?? true ? 'LIVE_TESTNET' : 'LIVE',
            'status' => 'SUBMITTED',
            'meta' => ['close_order' => $response],
        ]);

        $state->update([
            'state' => 'COOLDOWN',
            'position_side' => null,
            'entry_price' => null,
            'quantity' => 0,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => (float) $state->realized_pnl + $net,
            'daily_profit' => $net > 0 ? (float) $state->daily_profit + $net : (float) $state->daily_profit,
            'daily_loss' => $net < 0 ? (float) $state->daily_loss + abs($net) : (float) $state->daily_loss,
            'wins' => $net > 0 ? (int) $state->wins + 1 : (int) $state->wins,
            'losses' => $net < 0 ? (int) $state->losses + 1 : (int) $state->losses,
            'cooldown_until' => now()->addSeconds((int) ($settings['cooldownSeconds'] ?? 10)),
            'current_signal' => $reason,
        ]);
        $this->log($config->fresh('state'), 'LIVE_' . $reason, $state->current_symbol, 'CLOSE', 1, 'Live futures reduce-only close submitted.');
    }

    private function closePaperPosition(FutureTradeBotConfig $config, float $price, string $reason): void
    {
        $state = $this->state($config);
        $side = (string) $state->position_side;
        $direction = $side === 'LONG' ? 1 : -1;
        $entry = (float) $state->entry_price;
        $qty = (float) $state->quantity;
        $gross = ($price - $entry) * $qty * $direction;
        $fees = $price * $qty * 0.0004;
        $net = $gross - $fees;

        $config->trades()->create([
            'symbol' => (string) $state->current_symbol,
            'side' => 'CLOSE_' . $side,
            'entry_price' => $entry,
            'exit_price' => $price,
            'quantity' => $qty,
            'leverage' => (float) ($config->mergedSettings()['leverage'] ?? 3),
            'gross_pnl' => $gross,
            'fees' => $fees,
            'net_pnl' => $net,
            'reason' => $reason,
            'mode' => 'PAPER',
            'status' => 'FILLED',
        ]);

        $state->update([
            'state' => 'COOLDOWN',
            'position_side' => null,
            'entry_price' => null,
            'quantity' => 0,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => (float) $state->realized_pnl + $net,
            'daily_profit' => $net > 0 ? (float) $state->daily_profit + $net : (float) $state->daily_profit,
            'daily_loss' => $net < 0 ? (float) $state->daily_loss + abs($net) : (float) $state->daily_loss,
            'wins' => $net > 0 ? (int) $state->wins + 1 : (int) $state->wins,
            'losses' => $net < 0 ? (int) $state->losses + 1 : (int) $state->losses,
            'cooldown_until' => now()->addSeconds((int) ($config->mergedSettings()['cooldownSeconds'] ?? 10)),
            'current_signal' => $reason,
        ]);
        $this->log($config->fresh('state'), $reason, $state->current_symbol, 'CLOSE_' . $side, 1, 'Paper futures position closed.');
    }

    private function scoreTicker(array $ticker, array $settings): array
    {
        $price      = (float) ($ticker['lastPrice'] ?? $ticker['price'] ?? 0);
        $change     = (float) ($ticker['priceChangePercent'] ?? 0);
        $quoteVolume = (float) ($ticker['quoteVolume'] ?? 0);
        $high       = (float) ($ticker['highPrice'] ?? $price);
        $low        = (float) ($ticker['lowPrice'] ?? $price);
        $vwap       = (float) ($ticker['weightedAvgPrice'] ?? 0);
        $openPrice  = (float) ($ticker['openPrice'] ?? $price);

        $rangePct   = $price > 0 ? (($high - $low) / $price) * 100 : 0;

        // --- Signal Direction (replaces raw 24h change direction) ---
        // Use VWAP cross: price above VWAP = recent buying pressure (bullish)
        $vwapBullish = $vwap > 0 && $price > $vwap;

        // Price position within 24h range: 0 = at low, 1 = at high
        $rangePosition = ($high > $low) ? (($price - $low) / ($high - $low)) : 0.5;

        // Short-term momentum: current price vs open price (intraday direction)
        $intradayChangePct = $openPrice > 0 ? (($price - $openPrice) / $openPrice) * 100 : $change;

        // Signal requires BOTH VWAP confirmation AND price in upper/lower zone
        if ($vwapBullish && $rangePosition >= 0.45 && $intradayChangePct >= 0) {
            $rawSignal = 'BUY';
        } elseif (! $vwapBullish && $rangePosition <= 0.55 && $intradayChangePct <= 0) {
            $rawSignal = 'SELL';
        } else {
            // Conflicting signals — lower confidence, use 24h change as tiebreak
            $rawSignal = $change >= 0 ? 'BUY' : 'SELL';
        }

        // --- Confidence Scoring (no hardcoded base) ---
        // Volume quality: how significant is the market activity
        $volumeScore = min(0.30, log10(max($quoteVolume, 1)) / 35);

        // Momentum strength: intraday change magnitude (not raw 24h)
        $momentumScore = min(0.35, abs($intradayChangePct) / 10);

        // Range quality: moderate range preferred — too tight = no opportunity, too wide = choppy
        $rangeScore = $rangePct >= 0.5 && $rangePct <= 6
            ? min(0.20, $rangePct / 15)
            : ($rangePct > 6 ? max(0.0, 0.20 - ($rangePct - 6) * 0.02) : 0.05);

        // VWAP alignment bonus
        $alignmentBonus = ($vwapBullish && $rawSignal === 'BUY') || (! $vwapBullish && $rawSignal === 'SELL') ? 0.12 : 0.0;

        // Direction consistency: intraday and 24h change pointing same way
        $consistencyBonus = (($rawSignal === 'BUY' && $change >= 0 && $intradayChangePct >= 0) ||
                             ($rawSignal === 'SELL' && $change <= 0 && $intradayChangePct <= 0)) ? 0.08 : 0.0;

        $confidence = round(min(0.99, $volumeScore + $momentumScore + $rangeScore + $alignmentBonus + $consistencyBonus), 4);

        // Choppy: large range, tiny net move — avoid regardless
        $choppy  = $rangePct > 9 && abs($change) < 1.5;
        $signal  = $choppy && ($settings['avoidChoppyMarket'] ?? true) ? 'WAIT' : $rawSignal;

        $reasonParts = [];
        if ($signal === 'WAIT') {
            $reasonParts[] = 'Choppy futures market avoided';
        } else {
            $reasonParts[] = $vwapBullish ? 'Price above VWAP (bullish)' : 'Price below VWAP (bearish)';
            $reasonParts[] = sprintf('Range position %.0f%%', $rangePosition * 100);
            $reasonParts[] = sprintf('Intraday %.2f%%', $intradayChangePct);
        }

        return [
            'symbol'          => (string) ($ticker['symbol'] ?? 'BTCUSDT'),
            'signal'          => $signal,
            'price'           => $price,
            'changePercent'   => round($change, 4),
            'intradayChange'  => round($intradayChangePct, 4),
            'volume'          => $quoteVolume,
            'rangePercent'    => round($rangePct, 4),
            'rangePosition'   => round($rangePosition, 4),
            'vwapBullish'     => $vwapBullish,
            'confidence'      => $confidence,
            'timeframe'       => $settings['timeframe'] ?? '5m',
            'reason'          => implode(' | ', $reasonParts),
        ];
    }

    private function marketSnapshot(array $settings): array
    {
        $symbols = array_map('strtoupper', (array) ($settings['symbols'] ?? []));
        $cacheKey = 'future_trade_market_snapshot_' . md5(implode(',', $symbols));
        try {
            $base = rtrim((string) env('BINANCE_FUTURES_API_URL', 'https://fapi.binance.com'), '/');
            $rows = Http::timeout(7)->get($base . '/fapi/v1/ticker/24hr')->throw()->json();
            $result = collect($rows)->filter(fn ($row) => in_array((string) ($row['symbol'] ?? ''), $symbols, true))->values()->all();
            if (! empty($result)) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 60);
            }
            return $result;
        } catch (Throwable) {
            // Return last known good prices rather than fake mock data
            return \Illuminate\Support\Facades\Cache::get($cacheKey, []);
        }
    }

    private function syntheticOrderbook(float $price): array
    {
        if ($price <= 0) {
            return ['bids' => [], 'asks' => []];
        }

        $bids = [];
        $asks = [];
        for ($i = 1; $i <= 8; $i++) {
            $bids[] = ['price' => round($price * (1 - ($i * 0.00015)), 8), 'qty' => round(10 / $i, 4)];
            $asks[] = ['price' => round($price * (1 + ($i * 0.00015)), 8), 'qty' => round(9 / $i, 4)];
        }

        return ['bids' => $bids, 'asks' => $asks];
    }

    private function appendChartPoint(FutureTradeBotState $state, string $symbol, float $price): array
    {
        $points = (array) ($state->chart_points ?? []);
        $points[] = ['time' => now()->format('H:i:s'), 'symbol' => $symbol, 'price' => $price, 'pnl' => (float) $state->unrealized_pnl_percent];
        return array_slice($points, -120);
    }

    private function stopLossPrice(float $entry, string $side, array $settings): float
    {
        $pct = (float) ($settings['stopLossPercent'] ?? 0.25) / 100;
        return $side === 'SHORT' ? $entry * (1 + $pct) : $entry * (1 - $pct);
    }

    private function takeProfitPrice(float $entry, string $side, array $settings): float
    {
        $pct = (float) ($settings['takeProfitPercent'] ?? 0.45) / 100;
        return $side === 'SHORT' ? $entry * (1 - $pct) : $entry * (1 + $pct);
    }

    private function trailingPrice(float $peak, string $side, array $settings): float
    {
        $pct = (float) ($settings['trailingTakeProfitPercent'] ?? 0.18) / 100;
        return $side === 'SHORT' ? $peak * (1 + $pct) : $peak * (1 - $pct);
    }

    private function state(FutureTradeBotConfig $config): FutureTradeBotState
    {
        return $config->state ?: $config->state()->create();
    }

    private function accountSummary(FutureTradeBotConfig $config): array
    {
        $trades = $config->trades()
            ->whereNotNull('net_pnl')
            ->get(['net_pnl']);

        $botProfit = (float) $trades
            ->filter(fn ($trade) => (float) $trade->net_pnl > 0)
            ->sum(fn ($trade) => (float) $trade->net_pnl);

        $botLoss = (float) $trades
            ->filter(fn ($trade) => (float) $trade->net_pnl < 0)
            ->sum(fn ($trade) => abs((float) $trade->net_pnl));

        $summary = [
            'walletBalance' => null,
            'availableBalance' => null,
            'marginBalance' => null,
            'botProfit' => $botProfit,
            'botLoss' => $botLoss,
            'totalProfit' => $botProfit - $botLoss,
            'balanceSource' => $this->isLiveMode($config) ? 'BINANCE_FUTURES' : 'PAPER_LEDGER',
            'accountAsset' => 'USDT',
        ];

        if (! $this->isLiveMode($config)) {
            return $summary;
        }

        try {
            $account = $this->liveClient($config->mergedSettings())->fetchAccountInfo();
            $summary['walletBalance'] = isset($account['totalWalletBalance']) ? (float) $account['totalWalletBalance'] : null;
            $summary['availableBalance'] = isset($account['availableBalance']) ? (float) $account['availableBalance'] : null;
            $summary['marginBalance'] = isset($account['totalMarginBalance']) ? (float) $account['totalMarginBalance'] : null;
        } catch (Throwable $e) {
            $summary['balanceSource'] = 'BINANCE_UNAVAILABLE';
            $this->log($config->fresh('state'), 'BALANCE_SYNC_FAILED', $config->state?->current_symbol, null, 0, $e->getMessage());
        }

        return $summary;
    }

    private function isLiveMode(FutureTradeBotConfig $config): bool
    {
        return (bool) $config->enable_live_trading
            && (bool) ($config->mergedSettings()['liveTrading'] ?? false)
            && (bool) config('future_trade.enable_live_trading');
    }

    private function liveClient(array $settings): BinanceFuturesClient
    {
        if (! (bool) config('future_trade.enable_live_trading')) {
            throw new \RuntimeException('Future Trade live trading is disabled by environment.');
        }

        $baseUrl = (bool) ($settings['forceTestnet'] ?? true)
            ? config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
            : config('services.binance.futures_api_url', 'https://fapi.binance.com');

        return new BinanceFuturesClient(
            apiKey: (string) env('BINANCE_API_KEY', ''),
            apiSecret: (string) env('BINANCE_API_SECRET', ''),
            baseUrl: (string) $baseUrl,
        );
    }

    private function symbolFilters(array $exchangeInfo, string $symbol): array
    {
        $symbolInfo = collect((array) ($exchangeInfo['symbols'] ?? []))->first(fn ($row) => strtoupper((string) ($row['symbol'] ?? '')) === strtoupper($symbol));
        $filters = collect((array) ($symbolInfo['filters'] ?? []));
        $lot = (array) ($filters->first(fn ($row) => ($row['filterType'] ?? null) === 'LOT_SIZE') ?? []);
        $price = (array) ($filters->first(fn ($row) => ($row['filterType'] ?? null) === 'PRICE_FILTER') ?? []);

        return [
            'stepSize' => (float) ($lot['stepSize'] ?? 0.001),
            'tickSize' => (float) ($price['tickSize'] ?? 0.01),
        ];
    }

    private function formatStep(float $value, float $step): string
    {
        $step = $step > 0 ? $step : 0.000001;
        $rounded = floor(max($value, $step) / $step) * $step;
        $decimals = max(0, min(8, strlen(rtrim(explode('.', rtrim(sprintf('%.8F', $step), '0'))[1] ?? '', '0'))));
        $raw = number_format($rounded, $decimals, '.', '');
        return rtrim(rtrim($raw, '0'), '.') ?: number_format($step, $decimals, '.', '');
    }

    private function log(FutureTradeBotConfig $config, string $event, ?string $symbol, ?string $signal, float $confidence, string $reason, array $context = []): void
    {
        $config->logs()->create([
            'event' => $event,
            'symbol' => $symbol,
            'signal' => $signal,
            'price' => $config->state?->current_price,
            'confidence' => $confidence,
            'reason' => $reason,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
