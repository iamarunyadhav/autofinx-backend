<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\Contracts\PredictionProviderInterface;
use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\Data\PredictionResult;
use App\Domain\Prediction\PredictionObservatory;
use Tests\TestCase;

final class PredictionObservatoryTest extends TestCase
{
    public function test_enabled_providers_are_returned_as_shadow_only(): void
    {
        config()->set('prediction_observatory.providers.test_a.enabled', true);
        config()->set('prediction_observatory.providers.test_a.influence', true);

        $snapshot = (new PredictionObservatory([new FakeProvider('test_a')]))->observe($this->request());

        $this->assertTrue($snapshot['shadow_only']);
        $this->assertFalse($snapshot['influence_enabled']);
        $this->assertFalse($snapshot['providers']['test_a']['influence']);
        $this->assertSame('BUY', $snapshot['providers']['test_a']['result']['direction']);
    }

    public function test_disabled_provider_is_not_called(): void
    {
        config()->set('prediction_observatory.providers.test_a.enabled', false);

        $snapshot = (new PredictionObservatory([new FakeProvider('test_a')]))->observe($this->request());

        $this->assertSame([], $snapshot['providers']);
    }

    public function test_one_provider_failure_does_not_hide_other_results(): void
    {
        config()->set('prediction_observatory.providers.good.enabled', true);
        config()->set('prediction_observatory.providers.bad.enabled', true);

        $snapshot = (new PredictionObservatory([
            new FakeProvider('good'), new FakeProvider('bad', true),
        ]))->observe($this->request());

        $this->assertTrue($snapshot['providers']['good']['result']['available']);
        $this->assertFalse($snapshot['providers']['bad']['result']['available']);
        $this->assertSame('provider_exception', $snapshot['providers']['bad']['result']['reason']);
    }

    private function request(): PredictionRequest
    {
        return new PredictionRequest('BTCUSDT', 'crypto', '15m', 900, 100, array_fill(0, 8, [
            'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100,
        ]));
    }
}

final class FakeProvider implements PredictionProviderInterface
{
    public function __construct(private readonly string $name, private readonly bool $fails = false) {}
    public function key(): string { return $this->name; }
    public function version(): string { return 'test-v1'; }
    public function predict(PredictionRequest $request): PredictionResult
    {
        if ($this->fails) {
            throw new \RuntimeException('test failure');
        }

        return new PredictionResult(
            provider: $this->name, providerVersion: $this->version(), generatedAt: now()->toIso8601String(),
            symbol: $request->symbol, marketType: $request->marketType, timeframe: $request->timeframe,
            horizonSeconds: $request->horizonSeconds, available: true, direction: 'BUY', confidence: 60,
        );
    }
}
