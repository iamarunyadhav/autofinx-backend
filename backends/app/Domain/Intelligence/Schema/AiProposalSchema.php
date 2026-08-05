<?php

namespace App\Domain\Intelligence\Schema;

/**
 * JSON schemas sent to the Anthropic API via output_config.format (structured outputs).
 *
 * Structured outputs guarantee the first text block of the response is valid JSON
 * matching the schema. Note: numeric bounds (minimum/maximum) are NOT supported by
 * the API schema grammar — all numeric clamping stays in AiDecisionSchemaValidator /
 * the calling service.
 */
class AiProposalSchema
{
    /**
     * Spot trade proposal — mirrors the contract enforced by AiDecisionSchemaValidator.
     */
    public static function spot(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'action',
                'symbol',
                'confidence',
                'reasoning_summary',
                'size_pct',
                'stop_loss_pct',
                'take_profit_pct',
                'time_stop_minutes',
                'requires_human_approval',
                'warnings',
            ],
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['BUY', 'SELL', 'HOLD', 'NO_TRADE', 'PAUSE_TRADING', 'REDUCE', 'REVIEW_REQUIRED'],
                ],
                'symbol' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'description' => 'Model conviction between 0 and 1.'],
                'reasoning_summary' => ['type' => 'string', 'description' => 'Factual justification, max 500 characters.'],
                'size_pct' => ['type' => 'number', 'description' => 'Percent of capital base to deploy (0-100).'],
                'stop_loss_pct' => ['type' => 'number', 'description' => 'Stop loss distance in percent (0-100).'],
                'take_profit_pct' => ['type' => 'number', 'description' => 'Take profit distance in percent (0-100).'],
                'time_stop_minutes' => ['type' => 'integer', 'description' => 'Max holding time in minutes (0-10080).'],
                'requires_human_approval' => ['type' => 'boolean'],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
