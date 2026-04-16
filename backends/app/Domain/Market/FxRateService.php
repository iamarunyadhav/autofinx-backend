<?php

namespace App\Domain\Market;

use App\Models\FxRateSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class FxRateService
{
    public function getRate(string $base, string $quote): float
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        if ($base === $quote) {
            return 1.0;
        }

        $cacheKey = $this->cacheKey($base, $quote);
        try {
            $cached = Redis::get($cacheKey);
            if ($cached !== null) {
                return (float) $cached;
            }
        } catch (\Throwable) {
            // Continue with API fallback.
        }

        $rate = $this->fetchProviderRate($base, $quote);
        if ($rate <= 0) {
            $rate = $this->fallbackRate($base, $quote);
        }

        $this->storeRate($base, $quote, $rate);

        return $rate;
    }

    public function convert(float $amount, string $base, string $quote): float
    {
        return $amount * $this->getRate($base, $quote);
    }

    public function quoteSnapshot(string $base, string $quote): array
    {
        $rate = $this->getRate($base, $quote);

        return [
            'base' => strtoupper($base),
            'quote' => strtoupper($quote),
            'rate' => $rate,
            'provider' => config('services.fx.provider', 'fallback'),
            'ts' => now()->toIso8601String(),
        ];
    }

    private function fetchProviderRate(string $base, string $quote): float
    {
        $url = (string) config('services.fx.api_url');
        if ($url === '') {
            return 0.0;
        }

        $provider = strtolower((string) config('services.fx.provider', 'openexchangerates'));
        $apiKey = (string) config('services.fx.api_key', '');

        try {
            $response = Http::timeout(8)->get($url, array_filter([
                'app_id' => $apiKey !== '' ? $apiKey : null,
                'access_key' => $apiKey !== '' ? $apiKey : null,
                'base' => $base,
                'symbols' => $quote,
            ]));
            if (! $response->successful()) {
                return 0.0;
            }

            $payload = $response->json();
            if ($provider === 'openexchangerates' && isset($payload['rates'][$quote])) {
                return (float) $payload['rates'][$quote];
            }
            if (isset($payload['conversion_rates'][$quote])) {
                return (float) $payload['conversion_rates'][$quote];
            }
            if (isset($payload['rates'][$quote])) {
                return (float) $payload['rates'][$quote];
            }
            if (isset($payload['rate'])) {
                return (float) $payload['rate'];
            }
        } catch (\Throwable) {
            return 0.0;
        }

        return 0.0;
    }

    private function fallbackRate(string $base, string $quote): float
    {
        if ($base === 'USD' && $quote === 'LKR') {
            return (float) config('services.fx.default_usd_lkr', 315);
        }
        if ($base === 'LKR' && $quote === 'USD') {
            $usdLkr = (float) config('services.fx.default_usd_lkr', 315);
            return $usdLkr > 0 ? (1 / $usdLkr) : 0.0;
        }

        return 1.0;
    }

    private function storeRate(string $base, string $quote, float $rate): void
    {
        $ttl = max(30, (int) config('services.fx.ttl_sec', 120));
        try {
            Redis::setex($this->cacheKey($base, $quote), $ttl, (string) $rate);
        } catch (\Throwable) {
            // DB snapshot still persists.
        }

        FxRateSnapshot::create([
            'id' => (string) Str::uuid(),
            'provider' => (string) config('services.fx.provider', 'fallback'),
            'base' => $base,
            'quote' => $quote,
            'rate' => $rate,
            'ts' => now(),
        ]);
    }

    private function cacheKey(string $base, string $quote): string
    {
        return "fx:{$base}:{$quote}";
    }
}

