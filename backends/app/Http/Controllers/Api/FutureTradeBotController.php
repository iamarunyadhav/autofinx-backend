<?php

namespace App\Http\Controllers\Api;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Http\Controllers\Controller;
use App\Support\EnvFileEditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FutureTradeBotController extends Controller
{
    private const ENV_KEYS = [
        'FUTURE_TRADE_ENABLE_TESTNET_TRADING',
        'FUTURE_TRADE_ENABLE_LIVE_TRADING',
        'FUTURE_TRADE_FORCE_TESTNET',
        'BINANCE_FUTURES_API_URL',
        'BINANCE_FUTURES_TESTNET_API_URL',
        'BINANCE_FUTURES_LIVE_API_KEY',
        'BINANCE_FUTURES_LIVE_API_SECRET',
        'BINANCE_FUTURES_TESTNET_API_KEY',
        'BINANCE_FUTURES_TESTNET_API_SECRET',
    ];

    public function __construct(
        private readonly FutureTradeBotService $bot,
        private readonly EnvFileEditor $env,
    ) {}

    public function status(string $workspace): JsonResponse
    {
        return response()->json($this->bot->statusPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function dashboard(string $workspace): JsonResponse
    {
        return response()->json($this->bot->dashboardPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function start(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->start($this->bot->getOrCreateConfig($workspace))]);
    }

    public function stop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->stop($this->bot->getOrCreateConfig($workspace))]);
    }

    public function emergencyStop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->emergencyStop($this->bot->getOrCreateConfig($workspace))]);
    }

    public function resetPaper(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->resetPaper($this->bot->getOrCreateConfig($workspace))]);
    }

    public function config(string $workspace): JsonResponse
    {
        return response()->json($this->bot->configPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function updateConfig(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'nullable|array',
            'algorithm_notes' => 'nullable|string|max:20000',
        ]);

        return response()->json($this->bot->updateConfig($this->bot->getOrCreateConfig($workspace), $data));
    }

    public function envConfig(string $workspace): JsonResponse
    {
        return response()->json($this->envPayload());
    }

    public function updateEnvConfig(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|string|in:paper,testnet,live',
            'testnet_enabled' => 'required|boolean',
            'live_enabled' => 'required|boolean',
            'force_testnet' => 'required|boolean',
            'live_api_url' => 'required|url|max:255',
            'testnet_api_url' => 'required|url|max:255',
            'live_api_key' => 'nullable|string|max:512',
            'live_api_secret' => 'nullable|string|max:512',
            'testnet_api_key' => 'nullable|string|max:512',
            'testnet_api_secret' => 'nullable|string|max:512',
            'replace_live_secret' => 'nullable|boolean',
            'replace_testnet_secret' => 'nullable|boolean',
        ]);

        $mode = (string) $data['mode'];
        $values = [
            'FUTURE_TRADE_ENABLE_TESTNET_TRADING' => $mode === 'testnet' ? 'true' : 'false',
            'FUTURE_TRADE_ENABLE_LIVE_TRADING' => $mode === 'live' ? 'true' : 'false',
            'FUTURE_TRADE_FORCE_TESTNET' => $mode === 'testnet' || ($mode === 'paper' && (bool) $data['force_testnet']) ? 'true' : 'false',
            'BINANCE_FUTURES_API_URL' => (string) $data['live_api_url'],
            'BINANCE_FUTURES_TESTNET_API_URL' => (string) $data['testnet_api_url'],
        ];

        if (array_key_exists('live_api_key', $data)) {
            $values['BINANCE_FUTURES_LIVE_API_KEY'] = (string) ($data['live_api_key'] ?? '');
        }
        if ((bool) ($data['replace_live_secret'] ?? false)) {
            $values['BINANCE_FUTURES_LIVE_API_SECRET'] = (string) ($data['live_api_secret'] ?? '');
        }
        if (array_key_exists('testnet_api_key', $data)) {
            $values['BINANCE_FUTURES_TESTNET_API_KEY'] = (string) ($data['testnet_api_key'] ?? '');
        }
        if ((bool) ($data['replace_testnet_secret'] ?? false)) {
            $values['BINANCE_FUTURES_TESTNET_API_SECRET'] = (string) ($data['testnet_api_secret'] ?? '');
        }

        $this->env->write($values);
        Artisan::call('config:clear');

        config([
            'future_trade.enable_testnet_trading' => $values['FUTURE_TRADE_ENABLE_TESTNET_TRADING'] === 'true',
            'future_trade.enable_live_trading' => $values['FUTURE_TRADE_ENABLE_LIVE_TRADING'] === 'true',
            'future_trade.force_testnet' => $values['FUTURE_TRADE_FORCE_TESTNET'] === 'true',
            'services.binance.futures_api_url' => $values['BINANCE_FUTURES_API_URL'],
            'services.binance.futures_testnet_api_url' => $values['BINANCE_FUTURES_TESTNET_API_URL'],
        ]);

        foreach ([
            'BINANCE_FUTURES_LIVE_API_KEY' => 'services.binance.futures_live_api_key',
            'BINANCE_FUTURES_LIVE_API_SECRET' => 'services.binance.futures_live_api_secret',
            'BINANCE_FUTURES_TESTNET_API_KEY' => 'services.binance.futures_testnet_api_key',
            'BINANCE_FUTURES_TESTNET_API_SECRET' => 'services.binance.futures_testnet_api_secret',
        ] as $envKey => $configKey) {
            if (array_key_exists($envKey, $values)) {
                config([$configKey => $values[$envKey]]);
            }
        }

        return response()->json($this->envPayload());
    }

    public function trades(string $workspace): JsonResponse
    {
        return response()->json($this->bot->getOrCreateConfig($workspace)->trades()->orderByDesc('created_at')->limit(100)->get());
    }

    public function logs(string $workspace): JsonResponse
    {
        return response()->json($this->bot->getOrCreateConfig($workspace)->logs()->orderByDesc('occurred_at')->limit(100)->get());
    }

    private function envPayload(): array
    {
        $values = $this->env->read(self::ENV_KEYS);
        $mask = fn (string $value) => $value === '' ? '' : substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);

        return [
            'mode' => $values['FUTURE_TRADE_ENABLE_LIVE_TRADING'] === 'true'
                ? 'live'
                : ($values['FUTURE_TRADE_ENABLE_TESTNET_TRADING'] === 'true' ? 'testnet' : 'paper'),
            'testnet_enabled' => $values['FUTURE_TRADE_ENABLE_TESTNET_TRADING'] === 'true',
            'live_enabled' => $values['FUTURE_TRADE_ENABLE_LIVE_TRADING'] === 'true',
            'force_testnet' => $values['FUTURE_TRADE_FORCE_TESTNET'] !== 'false',
            'live_api_url' => $values['BINANCE_FUTURES_API_URL'] ?: 'https://fapi.binance.com',
            'testnet_api_url' => $values['BINANCE_FUTURES_TESTNET_API_URL'] ?: 'https://testnet.binancefuture.com',
            'live_api_key' => $values['BINANCE_FUTURES_LIVE_API_KEY'],
            'live_api_secret_masked' => $mask($values['BINANCE_FUTURES_LIVE_API_SECRET']),
            'testnet_api_key' => $values['BINANCE_FUTURES_TESTNET_API_KEY'],
            'testnet_api_secret_masked' => $mask($values['BINANCE_FUTURES_TESTNET_API_SECRET']),
            'live_configured' => $values['BINANCE_FUTURES_LIVE_API_KEY'] !== '' && $values['BINANCE_FUTURES_LIVE_API_SECRET'] !== '',
            'testnet_configured' => $values['BINANCE_FUTURES_TESTNET_API_KEY'] !== '' && $values['BINANCE_FUTURES_TESTNET_API_SECRET'] !== '',
        ];
    }
}
