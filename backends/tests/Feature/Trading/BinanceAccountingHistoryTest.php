<?php

namespace Tests\Feature\Trading;

use App\Domain\Exchange\BinanceFuturesClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinanceAccountingHistoryTest extends TestCase
{
    public function test_saturated_trade_window_is_split_without_skipping_timestamp_boundary(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push(array_fill(0, 1000, ['id' => 1]))
            ->push([['id' => 1, 'time' => 10]])->push([['id' => 2, 'time' => 11]]);
        $client = new BinanceFuturesClient('history-key', 'history-secret', 'https://testnet.binancefuture.com');
        $this->assertCount(2, $client->fetchAccountingTrades('BTCUSDT', 10, 11));
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r['startTime'] === 10 && $r['endTime'] === 10);
        Http::assertSent(fn ($r) => $r['startTime'] === 11 && $r['endTime'] === 11);
    }

    public function test_funding_pagination_requests_next_page_instead_of_silently_truncating(): void
    {
        Http::fakeSequence()->push(array_fill(0, 1000, ['tranId' => 1]))->push([['tranId' => 2]]);
        $client = new BinanceFuturesClient('history-key', 'history-secret', 'https://testnet.binancefuture.com');
        $this->assertCount(1001, $client->fetchAccountingFunding('BTCUSDT', 10, 20));
        Http::assertSent(fn ($r) => $r['page'] === 2);
    }

    public function test_malformed_success_response_cannot_be_treated_as_no_funding(): void
    {
        Http::fake(['*' => Http::response(['code' => -1])]);
        $this->expectException(\RuntimeException::class);
        (new BinanceFuturesClient('history-key', 'history-secret', 'https://testnet.binancefuture.com'))
            ->fetchAccountingFunding('BTCUSDT', 10, 20);
    }
}
