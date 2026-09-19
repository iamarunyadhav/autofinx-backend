<?php

namespace App\Domain\Nexus;

final class NexusCapabilityRegistryService
{
    public function snapshot(): array
    {
        $map = [
            'ratio_bot' => ['RatioBot/RatioBotBrainService.php', 'crypto', 'Existing forecast confirmation; verify strategy-specific live wiring.'],
            'quick_trade' => ['Trading/QuickTradeService.php', 'crypto', 'Timed trades; settlement evidence required.'],
            'robot_trade' => ['Trading/RobotSessionService.php', 'crypto', 'Session decisions; distinct from generic forecast replay.'],
            'future_trade' => ['FutureTrade/FutureTradeBotService.php', 'crypto-futures', 'Funding, execution costs and reconciliation matter.'],
            'fast2x' => ['Fast2X/Fast2XService.php', 'crypto', 'Native trigger/outcome calibration, variable holding time.'],
            'forex_grid' => ['ForexGrid/GridLotSizer.php', 'forex', 'Live account capability requires independent verification.'],
            'metals' => ['Pepperstone/BasketExitEngine.php', 'metals-energy', 'Live account capability requires independent verification.'],
            'forecast' => ['MarketIntelligence/UnifiedForecastEngine.php', 'multi', 'Model strength is not calibrated probability.'],
            'portfolio' => ['Portfolio/PortfolioAllocationService.php', 'multi', 'Allocation code exists; outcomes need validation.'],
            'execution' => ['Advanced/SmartOrderRouter.php', 'multi', 'Router presence is not evidence of fill quality.'],
            'risk' => ['Risk/RiskEngine.php', 'multi', 'Nexus cannot override trading risk decisions.'],
            'audit' => ['AuditResearch/AuditEventRecorder.php', 'multi', 'Read only structured observations; no raw secrets.'],
            'backtest' => ['RatioBot/ForecastHorizonBacktestService.php', 'crypto', 'Forecast replay is not execution simulation.'],
        ];
        $result = [];
        foreach ($map as $module => [$path, $market, $limitations]) {
            $exists = is_file(app_path('Domain/'.$path));
            $result[] = ['module' => $module, 'market' => $market, 'source' => 'app/Domain/'.$path,
                'code_present' => $exists, 'maturity' => $exists ? 'CODE_PRESENT_UNVERIFIED_RUNTIME' : 'NOT_FOUND_AT_REGISTERED_PATH',
                'live_verified' => false, 'limitations' => $limitations];
        }

        return $result;
    }
}
