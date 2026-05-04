<?php

namespace App\Http\Controllers\Api;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FutureTradeBotController extends Controller
{
    public function __construct(
        private readonly FutureTradeBotService $bot,
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
        return response()->json($this->envPayload($this->bot->getOrCreateConfig($workspace)));
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
        $botConfig = $this->bot->getOrCreateConfig($workspace);
        $settings = $botConfig->mergedSettings();

        $payload = [
            'settings' => [
                'envMode' => $mode,
                'envTestnetEnabled' => (bool) $data['testnet_enabled'],
                'envLiveEnabled' => (bool) $data['live_enabled'],
                'envForceTestnet' => (bool) $data['force_testnet'],
                'liveApiUrl' => (string) $data['live_api_url'],
                'testnetApiUrl' => (string) $data['testnet_api_url'],
                'liveApiKey' => array_key_exists('live_api_key', $data)
                    ? (string) ($data['live_api_key'] ?? '')
                    : (string) ($settings['liveApiKey'] ?? ''),
                'liveApiSecret' => (bool) ($data['replace_live_secret'] ?? false)
                    ? (string) ($data['live_api_secret'] ?? '')
                    : (string) ($settings['liveApiSecret'] ?? ''),
                'testnetApiKey' => array_key_exists('testnet_api_key', $data)
                    ? (string) ($data['testnet_api_key'] ?? '')
                    : (string) ($settings['testnetApiKey'] ?? ''),
                'testnetApiSecret' => (bool) ($data['replace_testnet_secret'] ?? false)
                    ? (string) ($data['testnet_api_secret'] ?? '')
                    : (string) ($settings['testnetApiSecret'] ?? ''),
                'liveTrading' => $mode !== 'paper',
                'paperTrading' => $mode === 'paper',
                'forceTestnet' => $mode !== 'live',
            ],
        ];

        $savedConfig = $this->bot->updateConfig($botConfig, $payload);

        return response()->json($this->envPayload($savedConfig));
    }

    public function trades(string $workspace): JsonResponse
    {
        return response()->json($this->bot->getOrCreateConfig($workspace)->trades()->orderByDesc('created_at')->limit(100)->get());
    }

    public function logs(string $workspace): JsonResponse
    {
        return response()->json($this->bot->getOrCreateConfig($workspace)->logs()->orderByDesc('occurred_at')->limit(100)->get());
    }

    private function envPayload($config): array
    {
        $settings = $config->mergedSettings();
        $mask = fn (string $value) => $value === '' ? '' : substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);

        return [
            'mode' => (string) ($settings['envMode'] ?? 'paper'),
            'testnet_enabled' => (bool) ($settings['envTestnetEnabled'] ?? false),
            'live_enabled' => (bool) ($settings['envLiveEnabled'] ?? false),
            'force_testnet' => (bool) ($settings['envForceTestnet'] ?? true),
            'live_api_url' => (string) ($settings['liveApiUrl'] ?? 'https://fapi.binance.com'),
            'testnet_api_url' => (string) ($settings['testnetApiUrl'] ?? 'https://testnet.binancefuture.com'),
            'live_api_key' => (string) ($settings['liveApiKey'] ?? ''),
            'live_api_secret_masked' => $mask((string) ($settings['liveApiSecret'] ?? '')),
            'testnet_api_key' => (string) ($settings['testnetApiKey'] ?? ''),
            'testnet_api_secret_masked' => $mask((string) ($settings['testnetApiSecret'] ?? '')),
            'live_configured' => (string) ($settings['liveApiKey'] ?? '') !== '' && (string) ($settings['liveApiSecret'] ?? '') !== '',
            'testnet_configured' => (string) ($settings['testnetApiKey'] ?? '') !== '' && (string) ($settings['testnetApiSecret'] ?? '') !== '',
        ];
    }
}
