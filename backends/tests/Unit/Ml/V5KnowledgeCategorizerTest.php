<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\V5KnowledgeCategorizer;
use Tests\TestCase;

final class V5KnowledgeCategorizerTest extends TestCase
{
    public function test_document_sections_become_independent_review_items(): void
    {
        $items = (new V5KnowledgeCategorizer)->split('Video notes', "CANDLE\nDoji needs context.\n\nTREND\nConfirm on closed candles.\n\nRISK\nAlways size the position.", 'OTHER');

        $this->assertCount(3, $items);
        $this->assertSame(['CANDLE', 'TREND', 'RISK'], array_column($items, 'category'));
        $this->assertStringContainsString('Doji', $items[0]['content']);
    }

    public function test_unlabelled_content_stays_in_the_selected_category(): void
    {
        $items = (new V5KnowledgeCategorizer)->split('Manual note', 'Only this exact note is retained.', 'SUPPORT_RESISTANCE');

        $this->assertSame('SUPPORT_RESISTANCE', $items[0]['category']);
        $this->assertSame('Only this exact note is retained.', $items[0]['content']);
    }
}
