<?php

namespace App\Domain\Billing;

use App\Jobs\ProcessPayout;
use App\Models\Payment;
use App\Models\WalletAccount;
use App\Models\WithdrawalSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutoWithdrawalService
{
    public function create(array $payload, ?string $userId): WithdrawalSchedule
    {
        $schedule = WithdrawalSchedule::create(array_merge($payload, [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'currency' => strtoupper((string) ($payload['currency'] ?? 'USD')),
            'method' => $this->normalizeMethod((string) ($payload['method'] ?? 'bank_transfer')),
            'next_run_at' => $this->computeNextRunAt((object) $payload),
        ]));

        return $schedule->refresh();
    }

    public function update(WithdrawalSchedule $schedule, array $payload): WithdrawalSchedule
    {
        $schedule->fill($payload);
        if (isset($payload['currency'])) {
            $schedule->currency = strtoupper((string) $payload['currency']);
        }
        if (isset($payload['method'])) {
            $schedule->method = $this->normalizeMethod((string) $payload['method']);
        }

        $schedule->next_run_at = $this->computeNextRunAt($schedule);
        $schedule->save();

        return $schedule->refresh();
    }

    public function runDue(?string $workspaceId = null, int $limit = 50): array
    {
        $query = WithdrawalSchedule::where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(max(1, min($limit, 200)));

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }

        $rows = $query->get();
        $results = [];
        foreach ($rows as $schedule) {
            $results[] = $this->runOne($schedule, false);
        }

        return [
            'checked' => $rows->count(),
            'results' => $results,
        ];
    }

    public function runOne(WithdrawalSchedule $schedule, bool $force): array
    {
        return DB::transaction(function () use ($schedule, $force): array {
            /** @var WithdrawalSchedule $locked */
            $locked = WithdrawalSchedule::where('id', $schedule->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                return [
                    'schedule_id' => $locked->id,
                    'status' => 'inactive',
                    'message' => 'Schedule is disabled.',
                ];
            }

            if (! $force && $locked->next_run_at && now()->lt($locked->next_run_at)) {
                return [
                    'schedule_id' => $locked->id,
                    'status' => 'not_due',
                    'next_run_at' => $locked->next_run_at,
                ];
            }

            $account = WalletAccount::where('workspace_id', $locked->workspace_id)
                ->where('currency', strtoupper((string) $locked->currency))
                ->where('type', 'cash')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                $locked->last_status = 'failed';
                $locked->last_error = 'wallet_account_missing';
                $locked->last_run_at = now();
                $locked->next_run_at = $this->computeNextRunAt($locked);
                $locked->save();

                return [
                    'schedule_id' => $locked->id,
                    'status' => 'failed',
                    'message' => 'Wallet account not found.',
                ];
            }

            $available = max(0, (float) ($account->available ?? $account->balance ?? 0));
            $amount = $this->resolveAmount($locked, $available);
            $minPayout = max(0, (float) $locked->min_payout_amount);
            if ($amount < $minPayout || $amount <= 0) {
                $locked->last_status = 'skipped_minimum';
                $locked->last_error = null;
                $locked->last_run_at = now();
                $locked->next_run_at = $this->computeNextRunAt($locked);
                $locked->save();

                return [
                    'schedule_id' => $locked->id,
                    'status' => 'skipped_minimum',
                    'amount' => $amount,
                    'minimum' => $minPayout,
                ];
            }

            $amount = min($amount, $available);
            if ($amount <= 0) {
                $locked->last_status = 'skipped_balance';
                $locked->last_error = null;
                $locked->last_run_at = now();
                $locked->next_run_at = $this->computeNextRunAt($locked);
                $locked->save();

                return [
                    'schedule_id' => $locked->id,
                    'status' => 'skipped_balance',
                    'amount' => $amount,
                ];
            }

            $payment = Payment::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $locked->workspace_id,
                'invoice_id' => null,
                'payment_method_id' => null,
                'amount' => $amount,
                'status' => 'pending',
                'provider' => $locked->method,
                'reference' => 'auto_wd_' . Str::random(10),
                'metadata' => [
                    'currency' => strtoupper((string) $locked->currency),
                    'direction' => 'debit',
                    'trigger' => 'schedule',
                    'withdrawal_schedule_id' => $locked->id,
                    'destination' => $locked->destination_meta,
                ],
                'idempotency_key' => (string) Str::uuid(),
            ]);

            ProcessPayout::dispatch($payment->id);

            $locked->last_status = 'queued';
            $locked->last_error = null;
            $locked->last_run_at = now();
            $locked->next_run_at = $this->computeNextRunAt($locked);
            $locked->save();

            return [
                'schedule_id' => $locked->id,
                'status' => 'queued',
                'payment_id' => $payment->id,
                'amount' => $amount,
                'currency' => strtoupper((string) $locked->currency),
            ];
        });
    }

    public function computeNextRunAt(object $schedule, ?Carbon $from = null): Carbon
    {
        $timezone = (string) ($schedule->timezone ?? 'Asia/Colombo');
        $cadence = strtolower((string) ($schedule->cadence ?? 'weekly'));
        $hour = max(0, min(23, (int) ($schedule->hour ?? 0)));
        $minute = max(0, min(59, (int) ($schedule->minute ?? 0)));

        $now = ($from ?: now())->copy()->setTimezone($timezone)->second(0);
        $candidate = $now->copy();

        if ($cadence === 'hourly') {
            $candidate->minute($minute);
            if ($candidate->lessThanOrEqualTo($now)) {
                $candidate->addHour();
            }
            return $candidate->setTimezone('UTC');
        }

        if ($cadence === 'daily') {
            $candidate = $now->copy()->startOfDay()->setTime($hour, $minute);
            if ($candidate->lessThanOrEqualTo($now)) {
                $candidate->addDay();
            }
            return $candidate->setTimezone('UTC');
        }

        if ($cadence === 'monthly') {
            $dom = max(1, min(28, (int) ($schedule->day_of_month ?? 1)));
            $candidate = $now->copy()->startOfMonth()->day($dom)->setTime($hour, $minute);
            if ($candidate->lessThanOrEqualTo($now)) {
                $candidate->addMonthNoOverflow()->startOfMonth()->day($dom)->setTime($hour, $minute);
            }
            return $candidate->setTimezone('UTC');
        }

        $targetDow = max(0, min(6, (int) ($schedule->day_of_week ?? 1)));
        $candidate = $now->copy()->startOfDay()->setTime($hour, $minute);
        $offset = ($targetDow - $candidate->dayOfWeek + 7) % 7;
        $candidate->addDays($offset);
        if ($candidate->lessThanOrEqualTo($now)) {
            $candidate->addWeek();
        }

        return $candidate->setTimezone('UTC');
    }

    private function resolveAmount(WithdrawalSchedule $schedule, float $available): float
    {
        $mode = strtolower((string) $schedule->amount_mode);
        $value = max(0.0, (float) $schedule->amount_value);
        $reserve = max(0.0, (float) $schedule->reserve_amount);

        if ($mode === 'percent_available') {
            return ($available * $value) / 100;
        }
        if ($mode === 'excess_over_reserve') {
            return max(0.0, $available - $reserve);
        }

        return $value;
    }

    private function normalizeMethod(string $method): string
    {
        $method = strtolower(trim($method));
        return match ($method) {
            'bank', 'bank_transfer', 'manual' => 'bank_transfer',
            'card', 'credit_card', 'debit_card' => 'stripe',
            default => $method,
        };
    }
}

