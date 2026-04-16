<?php

use App\Domain\Intelligence\AiDecisionSchemaValidator;
use App\Domain\Intelligence\Data\AiTradeProposal;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function validPayload(array $overrides = []): array
{
    return array_merge([
        'action' => 'BUY',
        'symbol' => 'BTCUSDT',
        'confidence' => 0.72,
        'reasoning_summary' => 'Momentum and signal alignment support a supervised spot entry.',
        'size_pct' => 1.25,
        'stop_loss_pct' => 0.8,
        'take_profit_pct' => 1.6,
        'time_stop_minutes' => 90,
        'requires_human_approval' => false,
        'warnings' => [],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// All 7 allowed action types
// ---------------------------------------------------------------------------

it('accepts BUY action', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['action' => 'BUY']));
    expect($proposal->action)->toBe('BUY')
        ->and($proposal->isTradeAction())->toBeTrue()
        ->and($proposal->isTradingAction())->toBeTrue()
        ->and($proposal->isReductionAction())->toBeFalse()
        ->and($proposal->requiresReview())->toBeFalse()
        ->and($proposal->isPauseAction())->toBeFalse();
});

it('accepts SELL action', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['action' => 'sell']));
    expect($proposal->action)->toBe('SELL')
        ->and($proposal->isTradeAction())->toBeTrue();
});

it('accepts HOLD action', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'HOLD',
        'size_pct' => 0,
    ]));
    expect($proposal->action)->toBe('HOLD')
        ->and($proposal->isTradeAction())->toBeFalse()
        ->and($proposal->isTradingAction())->toBeFalse();
});

it('accepts NO_TRADE action', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'NO_TRADE',
        'size_pct' => 0,
    ]));
    expect($proposal->action)->toBe('NO_TRADE')
        ->and($proposal->isTradeAction())->toBeFalse();
});

it('accepts PAUSE_TRADING action', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'PAUSE_TRADING',
        'size_pct' => 0,
    ]));
    expect($proposal->action)->toBe('PAUSE_TRADING')
        ->and($proposal->isPauseAction())->toBeTrue()
        ->and($proposal->isTradeAction())->toBeFalse();
});

it('accepts REDUCE action', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'REDUCE',
        'size_pct' => 0.5,
    ]));
    expect($proposal->action)->toBe('REDUCE')
        ->and($proposal->isReductionAction())->toBeTrue()
        ->and($proposal->isTradingAction())->toBeTrue()
        ->and($proposal->isTradeAction())->toBeFalse();
});

it('accepts REVIEW_REQUIRED action', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'REVIEW_REQUIRED',
        'size_pct' => 0,
        'warnings' => ['Unusual market condition detected'],
    ]));
    expect($proposal->action)->toBe('REVIEW_REQUIRED')
        ->and($proposal->requiresReview())->toBeTrue()
        ->and($proposal->isTradeAction())->toBeFalse()
        ->and($proposal->isTradingAction())->toBeFalse();
});

it('normalises action to uppercase', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['action' => 'buy']));
    expect($proposal->action)->toBe('BUY');
});

// ---------------------------------------------------------------------------
// Symbol normalisation
// ---------------------------------------------------------------------------

it('normalises slashed symbol to Binance format', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['symbol' => 'btc/usdt']));
    expect($proposal->symbol)->toBe('BTCUSDT');
});

it('rejects empty symbol', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['symbol' => '']));
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// Confidence
// ---------------------------------------------------------------------------

it('rejects confidence above 1', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['confidence' => 1.01]));
})->throws(InvalidArgumentException::class);

it('rejects confidence below 0', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['confidence' => -0.01]));
})->throws(InvalidArgumentException::class);

it('accepts confidence of exactly 0 and 1', function () {
    $v = new AiDecisionSchemaValidator();
    expect($v->validateOrFail(validPayload(['confidence' => 0, 'size_pct' => 0, 'action' => 'NO_TRADE']))->confidence)->toBe(0.0);
    expect($v->validateOrFail(validPayload(['confidence' => 1]))->confidence)->toBe(1.0);
});

// ---------------------------------------------------------------------------
// Size pct
// ---------------------------------------------------------------------------

it('rejects BUY with zero size_pct', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'BUY',
        'size_pct' => 0,
    ]));
})->throws(InvalidArgumentException::class);

it('rejects SELL with zero size_pct', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'SELL',
        'size_pct' => 0,
    ]));
})->throws(InvalidArgumentException::class);

it('rejects REDUCE with zero size_pct', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'REDUCE',
        'size_pct' => 0,
    ]));
})->throws(InvalidArgumentException::class);

it('rejects size_pct above 100', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['size_pct' => 101]));
})->throws(InvalidArgumentException::class);

it('rejects negative size_pct', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'NO_TRADE',
        'size_pct' => -1,
    ]));
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// Stop loss / take profit
// ---------------------------------------------------------------------------

it('accepts zero stop_loss_pct for HOLD action', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'action' => 'HOLD',
        'size_pct' => 0,
        'stop_loss_pct' => 0,
        'take_profit_pct' => 0,
    ]));
    expect($proposal->stopLossPct)->toBe(0.0);
});

it('rejects stop_loss_pct above 100', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['stop_loss_pct' => 101]));
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// Time stop
// ---------------------------------------------------------------------------

it('rejects negative time_stop_minutes', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['time_stop_minutes' => -1]));
})->throws(InvalidArgumentException::class);

it('rejects time_stop_minutes above 10080 (1 week)', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['time_stop_minutes' => 10081]));
})->throws(InvalidArgumentException::class);

it('accepts time_stop_minutes of 0', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['time_stop_minutes' => 0]));
    expect($proposal->timeStopMinutes)->toBe(0);
});

// ---------------------------------------------------------------------------
// Requires human approval
// ---------------------------------------------------------------------------

it('rejects non-boolean requires_human_approval', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['requires_human_approval' => 'yes']));
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// Warnings array
// ---------------------------------------------------------------------------

it('rejects non-array warnings', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['warnings' => 'bad']));
})->throws(InvalidArgumentException::class);

it('rejects warnings array with empty string', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['warnings' => ['valid', '']]));
})->throws(InvalidArgumentException::class);

it('accepts warnings with multiple non-empty strings', function () {
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'warnings' => ['low_liquidity', 'non_production_provider'],
    ]));
    expect($proposal->warnings)->toBe(['low_liquidity', 'non_production_provider']);
});

// ---------------------------------------------------------------------------
// Reasoning summary
// ---------------------------------------------------------------------------

it('rejects empty reasoning_summary', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['reasoning_summary' => '']));
})->throws(InvalidArgumentException::class);

it('rejects reasoning_summary above 500 characters', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload([
        'reasoning_summary' => str_repeat('x', 501),
    ]));
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// Missing required fields
// ---------------------------------------------------------------------------

it('rejects payload missing action field', function () {
    $payload = validPayload();
    unset($payload['action']);
    (new AiDecisionSchemaValidator())->validateOrFail($payload);
})->throws(InvalidArgumentException::class);

it('rejects payload missing confidence field', function () {
    $payload = validPayload();
    unset($payload['confidence']);
    (new AiDecisionSchemaValidator())->validateOrFail($payload);
})->throws(InvalidArgumentException::class);

it('rejects payload missing warnings field', function () {
    $payload = validPayload();
    unset($payload['warnings']);
    (new AiDecisionSchemaValidator())->validateOrFail($payload);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// Unknown action
// ---------------------------------------------------------------------------

it('rejects unknown action value', function () {
    (new AiDecisionSchemaValidator())->validateOrFail(validPayload(['action' => 'YOLO']));
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// JSON string input
// ---------------------------------------------------------------------------

it('accepts JSON string as input', function () {
    $json = json_encode(validPayload());
    $proposal = (new AiDecisionSchemaValidator())->validateOrFail($json);
    expect($proposal)->toBeInstanceOf(AiTradeProposal::class)
        ->and($proposal->action)->toBe('BUY');
});

it('rejects malformed JSON string', function () {
    (new AiDecisionSchemaValidator())->validateOrFail('not json at all');
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// AiTradeProposal::allowedActions()
// ---------------------------------------------------------------------------

it('allowedActions returns all 7 action types', function () {
    $allowed = AiTradeProposal::allowedActions();
    expect($allowed)->toContain('BUY')
        ->toContain('SELL')
        ->toContain('HOLD')
        ->toContain('NO_TRADE')
        ->toContain('PAUSE_TRADING')
        ->toContain('REDUCE')
        ->toContain('REVIEW_REQUIRED')
        ->toHaveCount(7);
});
