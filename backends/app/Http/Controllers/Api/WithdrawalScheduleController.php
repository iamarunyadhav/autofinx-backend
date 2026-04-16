<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\AutoWithdrawalService;
use App\Http\Controllers\Controller;
use App\Models\WithdrawalSchedule;
use Illuminate\Http\Request;

class WithdrawalScheduleController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
        ]);

        $rows = WithdrawalSchedule::where('workspace_id', $data['workspace_id'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($rows);
    }

    public function store(Request $request, AutoWithdrawalService $service)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'name' => 'required|string|max:120',
            'currency' => 'required|string|max:10',
            'method' => 'required|string|in:bank_transfer,bank,manual,payoneer,paypal,stripe,payhere,card,credit_card,debit_card',
            'cadence' => 'required|string|in:hourly,daily,weekly,monthly',
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'hour' => 'required|integer|min:0|max:23',
            'minute' => 'required|integer|min:0|max:59',
            'timezone' => 'nullable|string|max:64',
            'amount_mode' => 'required|string|in:fixed,percent_available,excess_over_reserve',
            'amount_value' => 'required|numeric|min:0',
            'reserve_amount' => 'nullable|numeric|min:0',
            'min_payout_amount' => 'nullable|numeric|min:0',
            'destination_meta' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $schedule = $service->create($data, $request->user()?->id);

        return response()->json($schedule, 201);
    }

    public function update(Request $request, WithdrawalSchedule $schedule, AutoWithdrawalService $service)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'currency' => 'sometimes|string|max:10',
            'method' => 'sometimes|string|in:bank_transfer,bank,manual,payoneer,paypal,stripe,payhere,card,credit_card,debit_card',
            'cadence' => 'sometimes|string|in:hourly,daily,weekly,monthly',
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'hour' => 'sometimes|integer|min:0|max:23',
            'minute' => 'sometimes|integer|min:0|max:59',
            'timezone' => 'sometimes|string|max:64',
            'amount_mode' => 'sometimes|string|in:fixed,percent_available,excess_over_reserve',
            'amount_value' => 'sometimes|numeric|min:0',
            'reserve_amount' => 'nullable|numeric|min:0',
            'min_payout_amount' => 'nullable|numeric|min:0',
            'destination_meta' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $updated = $service->update($schedule, $data);

        return response()->json($updated);
    }

    public function destroy(WithdrawalSchedule $schedule)
    {
        $schedule->delete();
        return response()->json(['ok' => true]);
    }

    public function runNow(WithdrawalSchedule $schedule, AutoWithdrawalService $service)
    {
        $result = $service->runOne($schedule, true);
        return response()->json($result);
    }
}

