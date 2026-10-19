<?php

namespace App\Domain\Ml\Shadow;

use App\Models\NexusEntry;

/**
 * Produces a human-review artifact from already approved V5 knowledge only.
 * This is deliberately a document export, not a model-training or execution input.
 */
final class V5KnowledgeExportService
{
    /** @return list<NexusEntry> */
    public function approved(string $workspace): array
    {
        return NexusEntry::query()
            ->where('workspace_id', $workspace)
            ->where('kind', 'candidate')
            ->where('status', 'APPROVED')
            ->where('payload->source', 'v5_knowledge_review')
            ->latest()
            ->get()
            ->all();
    }

    /** @param list<NexusEntry> $items */
    public function text(array $items): string
    {
        $lines = [
            'AUTOFINX V5 RESEARCH KNOWLEDGE REVIEW',
            'Status: HUMAN-APPROVED RESEARCH NOTES ONLY',
            'Safety: This file does not train, promote, or execute a trading model.',
            'Generated (UTC): '.now('UTC')->toIso8601String(),
            '',
        ];

        foreach ($items as $number => $item) {
            $payload = (array) $item->payload;
            $verification = (array) data_get($payload, 'verification', []);
            $lines[] = sprintf('%d. %s', $number + 1, $item->title);
            $lines[] = 'Category: '.(string) data_get($payload, 'category', 'OTHER');
            $lines[] = 'Item status: '.(string) $item->status;
            $lines[] = 'Source type: '.(string) data_get($payload, 'source_type', 'MANUAL_NOTE');
            $lines[] = 'Document review: '.(string) data_get($verification, 'document_review', 'PENDING');
            $lines[] = 'Human review: '.(string) data_get($verification, 'human_review', 'PENDING');
            $lines[] = 'Backtest: '.(string) data_get($verification, 'backtest', 'PENDING');
            $lines[] = 'Paper trade: '.(string) data_get($verification, 'paper_trade', 'PENDING');
            $lines[] = 'Holdout: '.(string) data_get($verification, 'holdout', 'PENDING');
            $lines[] = 'Training eligible: NO — approval is not a training gate.';
            $lines[] = 'Content:';
            $lines[] = trim((string) data_get($payload, 'content', 'No extracted transcript was supplied.'));
            if ($sourceUrl = data_get($payload, 'source_url')) {
                $lines[] = 'Source URL: '.$sourceUrl;
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }
}
