<?php

namespace App\Domain\Ml\Shadow;

use App\Models\NexusEntry;
use App\Models\V5MarketObservation;

/** Isolated knowledge paper ledger. It never places orders or writes baseline ML state. */
final class V5KnowledgePaperRunner
{
    public function run(string $workspace, NexusEntry $knowledge): NexusEntry
    {
        $payload = (array) $knowledge->payload;
        $structured = (bool) data_get($payload, 'structured_rule', false);
        $ledgerEntry = NexusEntry::firstOrCreate(
            ['workspace_id' => $workspace, 'kind' => 'experiment', 'fingerprint' => hash('sha256', json_encode(['v5-knowledge-paper-v1', $workspace, $knowledge->id], JSON_THROW_ON_ERROR))],
            ['title' => 'Knowledge paper ledger: '.$knowledge->title, 'status' => 'RUNNING', 'evidence_level' => 'C', 'payload' => ['source' => 'v5_knowledge_paper_ledger', 'knowledge_id' => $knowledge->id, 'paper_trades' => [], 'training_eligible' => false, 'execution_authorized' => false, 'baseline_autopilot_untouched' => true]]
        );
        $ledger = (array) $ledgerEntry->payload;
        if ($knowledge->status !== 'APPROVED' || ! $structured) {
            $ledger['status'] = 'BLOCKED';
            $ledger['blocked_reason'] = $knowledge->status !== 'APPROVED' ? 'KNOWLEDGE_NOT_HUMAN_APPROVED' : 'UNSUPPORTED_UNSTRUCTURED_KNOWLEDGE';
        } else {
            $direction = strtoupper((string) (data_get($payload, 'rule.direction') ?: data_get($payload, 'direction') ?: data_get($payload, 'signal')));
            $direction = in_array($direction, ['LONG', 'BUY', 'UP'], true) ? 'LONG' : (in_array($direction, ['SHORT', 'SELL', 'DOWN'], true) ? 'SHORT' : null);
            $symbol = strtoupper((string) (data_get($payload, 'symbol') ?: data_get($payload, 'rule.symbol') ?: 'BTCUSDT'));
            $observation = V5MarketObservation::query()->where('symbol', $symbol)->latest('candle_close_ms')->first();
            if (! $direction || ! $observation) {
                $ledger['status'] = 'WAITING';
                $ledger['blocked_reason'] = ! $direction ? 'NO_DIRECTION_RULE' : 'NO_CLOSED_MARKET_OBSERVATION';
            } else {
                $trades = (array) ($ledger['paper_trades'] ?? []);
                $last = end($trades);
                if (is_array($last) && ($last['status'] ?? null) === 'OPEN' && ($last['candle_close_ms'] ?? null) !== $observation->candle_close_ms) {
                    $move = ((float) $observation->close - (float) $last['entry_price']) / max((float) $last['entry_price'], 0.00000001);
                    $pnl = ($last['direction'] ?? 'LONG') === 'SHORT' ? -$move : $move;
                    $trades[array_key_last($trades)] = array_merge($last, ['status' => 'SETTLED', 'exit_price' => (float) $observation->close, 'pnl_pct' => round($pnl * 100, 6), 'settled_at' => now()->toIso8601String(), 'outcome' => $pnl > 0 ? 'WIN' : ($pnl < 0 ? 'LOSS' : 'FLAT')]);
                }
                if (! is_array($last) || ($last['candle_close_ms'] ?? null) !== $observation->candle_close_ms) {
                    $trades[] = ['id' => hash('sha256', $knowledge->id.'|'.$observation->candle_close_ms), 'symbol' => $symbol, 'direction' => $direction, 'entry_price' => (float) $observation->close, 'candle_close_ms' => $observation->candle_close_ms, 'status' => 'OPEN', 'opened_at' => now()->toIso8601String(), 'source' => 'approved_nexus_knowledge'];
                }
                $ledger['paper_trades'] = array_slice($trades, -200);
                $ledger['status'] = 'RUNNING';
                $ledger['last_observation'] = ['symbol' => $symbol, 'close' => (float) $observation->close, 'candle_close_ms' => $observation->candle_close_ms];
                $ledger['blocked_reason'] = null;
            }
        }
        $ledgerEntry->update(['status' => $ledger['status'], 'payload' => $ledger]);
        return $ledgerEntry;
    }

    public function runApproved(?string $workspace = null): int
    {
        $count = 0;
        NexusEntry::query()->where('kind', 'candidate')->where('status', 'APPROVED')->when($workspace, fn ($q) => $q->where('workspace_id', $workspace))->lazyById(50)->each(function (NexusEntry $entry) use (&$count): void {
            $this->run((string) $entry->workspace_id, $entry);
            $count++;
        });
        return $count;
    }
}
