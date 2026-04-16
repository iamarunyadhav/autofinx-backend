<?php

namespace App\Domain\Intelligence;

use App\Domain\Exchange\BinanceSymbolNormalizer;
use App\Domain\Intelligence\Data\AiTradeProposal;
use InvalidArgumentException;

class AiDecisionSchemaValidator
{
    public function validateOrFail(array|string $payload): AiTradeProposal
    {
        $decoded = is_string($payload) ? json_decode(trim($payload), true) : $payload;
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('AI proposal must be a JSON object.');
        }

        $required = [
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
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new InvalidArgumentException("AI proposal is missing required field [{$field}].");
            }
        }

        $action = strtoupper((string) $decoded['action']);
        if (! in_array($action, ['BUY', 'SELL', 'HOLD', 'NO_TRADE', 'PAUSE_TRADING', 'REDUCE', 'REVIEW_REQUIRED'], true)) {
            throw new InvalidArgumentException('AI proposal action is invalid. Allowed: BUY, SELL, HOLD, NO_TRADE, PAUSE_TRADING, REDUCE, REVIEW_REQUIRED.');
        }

        $symbol = BinanceSymbolNormalizer::normalize((string) $decoded['symbol']);
        if ($symbol === '') {
            throw new InvalidArgumentException('AI proposal symbol is invalid.');
        }
        $decoded['symbol'] = $symbol;
        $decoded['action'] = $action;

        $confidence = $this->numeric($decoded['confidence'], 'confidence');
        if ($confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('AI proposal confidence must be between 0 and 1.');
        }

        $reason = trim((string) $decoded['reasoning_summary']);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('AI proposal reasoning_summary must be between 1 and 500 characters.');
        }
        $decoded['reasoning_summary'] = $reason;

        foreach (['size_pct', 'stop_loss_pct', 'take_profit_pct'] as $field) {
            $value = $this->numeric($decoded[$field], $field);
            if ($value < 0 || $value > 100) {
                throw new InvalidArgumentException("AI proposal {$field} must be between 0 and 100.");
            }
            $decoded[$field] = $value;
        }

        $minutes = filter_var($decoded['time_stop_minutes'], FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 0 || $minutes > 10080) {
            throw new InvalidArgumentException('AI proposal time_stop_minutes must be between 0 and 10080.');
        }
        $decoded['time_stop_minutes'] = (int) $minutes;

        if (! is_bool($decoded['requires_human_approval'])) {
            throw new InvalidArgumentException('AI proposal requires_human_approval must be boolean.');
        }

        if (! is_array($decoded['warnings'])) {
            throw new InvalidArgumentException('AI proposal warnings must be an array.');
        }
        $decoded['warnings'] = array_values(array_map(function ($warning): string {
            $value = trim((string) $warning);
            if ($value === '') {
                throw new InvalidArgumentException('AI proposal warnings must contain non-empty strings.');
            }

            return $value;
        }, $decoded['warnings']));

        if (in_array($action, ['BUY', 'SELL', 'REDUCE'], true) && $decoded['size_pct'] <= 0) {
            throw new InvalidArgumentException('AI trade proposal size_pct must be greater than zero for BUY, SELL, or REDUCE.');
        }

        return AiTradeProposal::fromArray($decoded);
    }

    private function numeric(mixed $value, string $field): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("AI proposal {$field} must be numeric.");
        }

        return (float) $value;
    }
}
