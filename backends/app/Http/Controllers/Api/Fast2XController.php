<?php

namespace App\Http\Controllers\Api;

use App\Domain\Fast2X\BotStateService;
use App\Domain\Fast2X\Fast2XService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Fast2XController extends Controller
{
    public function __construct(
        private readonly Fast2XService $fast2x,
        private readonly BotStateService $states,
    ) {}

    public function status(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->statusPayload($this->fast2x->getOrCreateConfig($workspace)));
    }

    public function start(string $workspace): JsonResponse
    {
        $config = $this->fast2x->getOrCreateConfig($workspace);
        $state = $this->states->start($config);
        $this->fast2x->refreshLivePrice($config->fresh('state'));
        return response()->json(['state' => $state->fresh(), 'status' => $this->fast2x->statusPayload($config->fresh('state'))]);
    }

    public function pause(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->states->pause($this->fast2x->getOrCreateConfig($workspace))]);
    }

    public function stop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->states->stop($this->fast2x->getOrCreateConfig($workspace))]);
    }

    public function emergencyStop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->states->emergencyStop($this->fast2x->getOrCreateConfig($workspace))]);
    }

    public function resetPaper(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->states->resetPaper($this->fast2x->getOrCreateConfig($workspace))]);
    }

    public function config(string $workspace): JsonResponse
    {
        $config = $this->fast2x->getOrCreateConfig($workspace);
        return response()->json([
            'id' => $config->id,
            'settings' => $config->mergedSettings(),
            'paper_trading' => $config->paper_trading,
            'enable_live_trading' => $config->enable_live_trading,
            'ai_brain_enabled' => $config->ai_brain_enabled,
            'algorithm_markdown' => $config->algorithm_markdown,
            'available_symbols' => config('fast2x.available_symbols', ['BTCUSDT']),
        ]);
    }

    public function updateConfig(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'nullable|array',
            'enable_live_trading' => 'nullable|boolean',
            'ai_brain_enabled' => 'nullable|boolean',
            'algorithm_markdown' => 'nullable|string|max:20000',
        ]);

        return response()->json($this->fast2x->updateConfig($this->fast2x->getOrCreateConfig($workspace), $data));
    }

    public function envConfig(string $workspace): JsonResponse
    {
        return response()->json($this->envPayload($this->fast2x->getOrCreateConfig($workspace)));
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
        $config = $this->fast2x->getOrCreateConfig($workspace);
        $settings = $config->mergedSettings();
        $nextSettings = array_merge($settings, [
            'envMode' => $mode,
            'envTestnetEnabled' => (bool) $data['testnet_enabled'],
            'envLiveEnabled' => (bool) $data['live_enabled'],
            'envForceTestnet' => (bool) $data['force_testnet'],
            'liveApiUrl' => (string) $data['live_api_url'],
            'testnetApiUrl' => (string) $data['testnet_api_url'],
            'liveApiKey' => array_key_exists('live_api_key', $data) ? (string) ($data['live_api_key'] ?? '') : (string) ($settings['liveApiKey'] ?? ''),
            'liveApiSecret' => (bool) ($data['replace_live_secret'] ?? false) ? (string) ($data['live_api_secret'] ?? '') : (string) ($settings['liveApiSecret'] ?? ''),
            'testnetApiKey' => array_key_exists('testnet_api_key', $data) ? (string) ($data['testnet_api_key'] ?? '') : (string) ($settings['testnetApiKey'] ?? ''),
            'testnetApiSecret' => (bool) ($data['replace_testnet_secret'] ?? false) ? (string) ($data['testnet_api_secret'] ?? '') : (string) ($settings['testnetApiSecret'] ?? ''),
        ]);

        $config->update(['settings' => $nextSettings]);

        return response()->json($this->envPayload($config->fresh('state')));
    }

    public function testConnection(string $workspace): JsonResponse
    {
        try {
            $config = $this->fast2x->getOrCreateConfig($workspace);
            $settings = $config->mergedSettings();
            $mode = (string) ($settings['envMode'] ?? 'paper');
            if ($mode === 'paper') {
                return response()->json([
                    'connected' => false,
                    'mode' => 'paper',
                    'message' => 'Paper mode selected. Switch to Testnet or Live in My Config to test a Binance spot connection.',
                ]);
            }

            $forceTestnet = $mode !== 'live';
            $baseUrl = rtrim((string) ($forceTestnet ? ($settings['testnetApiUrl'] ?? 'https://testnet.binance.vision') : ($settings['liveApiUrl'] ?? 'https://api.binance.com')), '/');
            $apiKey = (string) ($forceTestnet ? ($settings['testnetApiKey'] ?? '') : ($settings['liveApiKey'] ?? ''));
            $apiSecret = (string) ($forceTestnet ? ($settings['testnetApiSecret'] ?? '') : ($settings['liveApiSecret'] ?? ''));

            if ($apiKey === '' || $apiSecret === '') {
                return response()->json([
                    'connected' => false,
                    'mode' => $mode,
                    'message' => 'API key and secret are required for the selected Fast2X connection mode.',
                ], 422);
            }

            Http::timeout(5)->get($baseUrl . '/api/v3/ping')->throw();
            $payload = ['timestamp' => (int) floor(microtime(true) * 1000), 'recvWindow' => 5000];
            ksort($payload);
            $query = http_build_query($payload, '', '&');
            $payload['signature'] = hash_hmac('sha256', $query, $apiSecret);
            $account = Http::withHeaders(['X-MBX-APIKEY' => $apiKey])->timeout(10)->get($baseUrl . '/api/v3/account', $payload)->throw()->json();
            $asset = collect((array) ($account['balances'] ?? []))
                ->first(fn ($row) => strtoupper((string) ($row['asset'] ?? '')) === 'USDT');

            return response()->json([
                'connected' => true,
                'mode' => $mode,
                'message' => $forceTestnet ? 'Binance Spot testnet connection OK. Fast2X execution is still paper-simulated in this build.' : 'Binance Spot live connection OK. Fast2X execution is still paper-simulated in this build.',
                'accountAsset' => strtoupper((string) ($asset['asset'] ?? 'USDT')) ?: 'USDT',
                'free' => isset($asset['free']) ? (float) $asset['free'] : 0,
                'locked' => isset($asset['locked']) ? (float) $asset['locked'] : 0,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'connected' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function trades(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->getOrCreateConfig($workspace)->trades()->orderByDesc('created_at')->limit(100)->get());
    }

    public function logs(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->getOrCreateConfig($workspace)->logs()->orderByDesc('occurred_at')->limit(100)->get());
    }

    public function livePrice(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->refreshLivePrice($this->fast2x->getOrCreateConfig($workspace)));
    }

    public function pnl(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->pnlPayload($this->fast2x->getOrCreateConfig($workspace)));
    }

    public function chart(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->chartPayload($this->fast2x->getOrCreateConfig($workspace)));
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
            'live_api_url' => (string) ($settings['liveApiUrl'] ?? 'https://api.binance.com'),
            'testnet_api_url' => (string) ($settings['testnetApiUrl'] ?? 'https://testnet.binance.vision'),
            'live_api_key' => (string) ($settings['liveApiKey'] ?? ''),
            'live_api_secret_masked' => $mask((string) ($settings['liveApiSecret'] ?? '')),
            'testnet_api_key' => (string) ($settings['testnetApiKey'] ?? ''),
            'testnet_api_secret_masked' => $mask((string) ($settings['testnetApiSecret'] ?? '')),
            'live_configured' => (string) ($settings['liveApiKey'] ?? '') !== '' && (string) ($settings['liveApiSecret'] ?? '') !== '',
            'testnet_configured' => (string) ($settings['testnetApiKey'] ?? '') !== '' && (string) ($settings['testnetApiSecret'] ?? '') !== '',
        ];
    }
}
