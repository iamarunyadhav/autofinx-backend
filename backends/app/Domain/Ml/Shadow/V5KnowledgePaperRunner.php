<?php

namespace App\Domain\Ml\Shadow;

use App\Models\NexusEntry;

/** Isolated knowledge paper ledger. It never places orders or writes baseline ML state. */
final class V5KnowledgePaperRunner
{
    public function run(string $workspace, NexusEntry $knowledge): NexusEntry
    {
        $payload = (array) $knowledge->payload;
        $structured = (bool) data_get($payload, 'structured_rule', false);
        $reason = $knowledge->status !== 'APPROVED'
            ? 'KNOWLEDGE_NOT_HUMAN_APPROVED'
            : (! $structured ? 'UNSUPPORTED_UNSTRUCTURED_KNOWLEDGE' : 'NO_MARKET_SNAPSHOT_OR_RULE_EXECUTOR');
        $status = 'BLOCKED';
        $ledger = ['source' => 'v5_knowledge_paper_ledger', 'knowledge_id' => $knowledge->id, 'status' => $status, 'blocked_reason' => $reason,
            'paper_trades' => [], 'training_eligible' => false, 'execution_authorized' => false, 'baseline_autopilot_untouched' => true];
        return NexusEntry::firstOrCreate(
            ['workspace_id' => $workspace, 'kind' => 'experiment', 'fingerprint' => hash('sha256', json_encode(['v5-knowledge-paper-v1', $workspace, $knowledge->id], JSON_THROW_ON_ERROR))],
            ['title' => 'Knowledge paper ledger: '.$knowledge->title, 'status' => $status, 'evidence_level' => 'C', 'payload' => $ledger]
        );
    }
}
