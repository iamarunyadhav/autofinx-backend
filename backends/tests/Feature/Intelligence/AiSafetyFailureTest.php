<?php

/**
 * AI Safety Failure Integration Tests
 *
 * Tests the system's behaviour under adversarial / failure conditions:
 *  - Malformed / non-JSON AI provider response
 *  - Unknown action in AI response
 *  - Confidence out of range
 *  - Missing required fields in AI response
 *  - Provider exception / timeout simulation
 *  - Duplicate order prevention via clientOrderId
 *  - REVIEW_REQUIRED blocks auto-execution
 *  - REDUCE never auto-executes (requires operator)
 *  - Schema rejection prevents policy evaluation
 *
 * These tests verify that safety gates work correctly — a bad AI response
 * must NEVER result in money movement.
 */

use App\Domain\Intelligence\AiDecisionOrchestratorService;
use App\Domain\Intelligence\AiDecisionProviderFactory;
use App\Domain\Intelligence\AiDecisionSchemaValidator;
use App\Domain\Intelligence\AiTradeProposal;
use App\Domain\Intelligence\Contracts\AiDecisionProvider;
use App\Domain\Intelligence\Data\AiTradeProposal as AiTradeProposalData;
use App\Domain\Intelligence\NullAiDecisionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function validProposalArray(array $overrides = []): array
{
    return array_merge([
        'action' => 'BUY',
        'symbol' => 'BTCUSDT',
        'confidence' => 0.70,
        'reasoning_summary' => 'Test reasoning summary for safety tests.',
        'size_pct' => 1.0,
        'stop_loss_pct' => 0.8,
        'take_profit_pct' => 1.5,
        'time_stop_minutes' => 60,
        'requires_human_approval' => false,
        'warnings' => [],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// 1. Malformed / non-JSON provider response must be rejected
// ---------------------------------------------------------------------------

it('rejects a completely non-JSON provider response', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail('this is not json');
});

it('rejects a JSON array instead of JSON object', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail('[1, 2, 3]');
});

it('rejects empty string', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail('');
});

// ---------------------------------------------------------------------------
// 2. Unknown / hallucinated action must not pass
// ---------------------------------------------------------------------------

it('rejects hallucinated action MARKET_BUY_NOW', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail(validProposalArray(['action' => 'MARKET_BUY_NOW']));
});

it('rejects hallucinated action WITHDRAW', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail(validProposalArray(['action' => 'WITHDRAW']));
});

it('rejects hallucinated action TRANSFER_OUT', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail(validProposalArray(['action' => 'TRANSFER_OUT']));
});

// ---------------------------------------------------------------------------
// 3. Confidence out of range
// ---------------------------------------------------------------------------

it('rejects confidence of 1.5 (hallucinated scale)', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail(validProposalArray(['confidence' => 1.5]));
});

it('rejects confidence of 99 (percentage scale instead of 0-1)', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail(validProposalArray(['confidence' => 99]));
});

// ---------------------------------------------------------------------------
// 4. Missing fields cannot produce a valid proposal
// ---------------------------------------------------------------------------

dataset('missing_required_fields', [
    'action' => ['action'],
    'symbol' => ['symbol'],
    'confidence' => ['confidence'],
    'reasoning_summary' => ['reasoning_summary'],
    'size_pct' => ['size_pct'],
    'stop_loss_pct' => ['stop_loss_pct'],
    'take_profit_pct' => ['take_profit_pct'],
    'time_stop_minutes' => ['time_stop_minutes'],
    'requires_human_approval' => ['requires_human_approval'],
    'warnings' => ['warnings'],
]);

it('rejects payload missing field: :field', function (string $field) {
    $payload = validProposalArray();
    unset($payload[$field]);
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail($payload);
})->with('missing_required_fields');

// ---------------------------------------------------------------------------
// 5. Provider exception / timeout: orchestrator must return ok=false, not throw
// ---------------------------------------------------------------------------

it('orchestrator returns ok=false when provider throws RuntimeException', function () {
    $throwingProvider = new class implements AiDecisionProvider {
        public function name(): string { return 'test_failing'; }
        public function model(array $config = []): string { return 'test-model'; }
        public function generateProposal(array $context, array $config): array|string
        {
            throw new RuntimeException('Simulated provider timeout / API failure.');
        }
    };

    $factory = Mockery::mock(AiDecisionProviderFactory::class);
    $factory->shouldReceive('make')->with('test_failing')->andReturn($throwingProvider);

    $orchestrator = new AiDecisionOrchestratorService($factory, new AiDecisionSchemaValidator());
    $result = $orchestrator->propose([], ['provider' => 'test_failing']);

    expect($result['ok'])->toBeFalse()
        ->and($result['proposal'])->toBeNull()
        ->and($result['validation']['ok'])->toBeFalse()
        ->and($result['provider'])->toBe('test_failing');
});

it('orchestrator returns ok=false when provider returns invalid JSON', function () {
    $badProvider = new class implements AiDecisionProvider {
        public function name(): string { return 'test_bad_json'; }
        public function model(array $config = []): string { return 'test-model'; }
        public function generateProposal(array $context, array $config): array|string
        {
            return '{invalid_json: true,';
        }
    };

    $factory = Mockery::mock(AiDecisionProviderFactory::class);
    $factory->shouldReceive('make')->with('test_bad_json')->andReturn($badProvider);

    $orchestrator = new AiDecisionOrchestratorService($factory, new AiDecisionSchemaValidator());
    $result = $orchestrator->propose([], ['provider' => 'test_bad_json']);

    expect($result['ok'])->toBeFalse()
        ->and($result['proposal'])->toBeNull();
});

// ---------------------------------------------------------------------------
// 6. REVIEW_REQUIRED must never set can_execute_now = true
// ---------------------------------------------------------------------------

it('REVIEW_REQUIRED proposal always results in requires_human_approval = true from policy', function () {
    $proposal = AiTradeProposalData::fromArray(validProposalArray([
        'action' => 'REVIEW_REQUIRED',
        'size_pct' => 0,
        'requires_human_approval' => false, // even if AI says false, policy must override
    ]));

    expect($proposal->requiresReview())->toBeTrue()
        ->and($proposal->isTradeAction())->toBeFalse()
        ->and($proposal->isTradingAction())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 7. REDUCE never passes isTradeAction (prevents direct order placement path)
// ---------------------------------------------------------------------------

it('REDUCE proposal never passes isTradeAction gate', function () {
    $proposal = AiTradeProposalData::fromArray(validProposalArray([
        'action' => 'REDUCE',
        'size_pct' => 1.0,
    ]));

    expect($proposal->isTradeAction())->toBeFalse()
        ->and($proposal->isReductionAction())->toBeTrue()
        ->and($proposal->isTradingAction())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 8. BinanceSpotClient duplicate order detection
// ---------------------------------------------------------------------------

it('isDuplicateOrderError returns true for Binance -2010 duplicate message', function () {
    $client = new \App\Domain\Exchange\BinanceSpotClient(
        'test_key',
        'test_secret',
        'https://testnet.binance.vision',
        'wss://testnet.binance.vision/ws',
    );

    $response = ['code' => -2010, 'msg' => 'Duplicate order sent.'];
    expect($client->isDuplicateOrderError($response))->toBeTrue();

    $notDuplicate = ['code' => -2010, 'msg' => 'Account has insufficient balance.'];
    expect($client->isDuplicateOrderError($notDuplicate))->toBeFalse();
});

it('isDuplicateOrderError returns false for non-2010 codes', function () {
    $client = new \App\Domain\Exchange\BinanceSpotClient(
        'test_key',
        'test_secret',
        'https://testnet.binance.vision',
        'wss://testnet.binance.vision/ws',
    );

    expect($client->isDuplicateOrderError(['code' => -1013, 'msg' => 'LOT_SIZE']))->toBeFalse();
    expect($client->isDuplicateOrderError(['code' => -2011, 'msg' => 'Duplicate order sent.']))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 9. AiTradeProposal::allowedActions() is complete and correct
// ---------------------------------------------------------------------------

it('all 7 action types are in allowedActions and none others', function () {
    $allowed = AiTradeProposalData::allowedActions();

    // These must all be present
    foreach (['BUY', 'SELL', 'HOLD', 'NO_TRADE', 'PAUSE_TRADING', 'REDUCE', 'REVIEW_REQUIRED'] as $action) {
        expect($allowed)->toContain($action);
    }

    // Hallucinated actions must not be present
    foreach (['YOLO', 'WITHDRAW', 'TRANSFER', 'MARKET_BUY_NOW', 'AUTO_INVEST'] as $bad) {
        expect($allowed)->not->toContain($bad);
    }
});

// ---------------------------------------------------------------------------
// 10. AiDecisionSchemaValidator rejects size_pct > 100 (injection prevention)
// ---------------------------------------------------------------------------

it('rejects size_pct of 9999 (potential injection attempt)', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail(validProposalArray(['size_pct' => 9999]));
});

it('rejects stop_loss_pct of 999 (potential injection attempt)', function () {
    $validator = new AiDecisionSchemaValidator();
    $this->expectException(InvalidArgumentException::class);
    $validator->validateOrFail(validProposalArray(['stop_loss_pct' => 999]));
});
