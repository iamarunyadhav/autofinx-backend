<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\AuditLog;
use App\Support\ConfigVersioning;
use App\Support\SchedulerSimulator;
use App\Domain\Scheduler\ScheduleGate;
use App\Support\Clock\Clock;

class ScheduleController extends Controller
{
    public function index(string $workspaceId)
    {
        return Schedule::where('workspace_id', $workspaceId)->get();
    }

    public function store(Request $request, string $workspaceId)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'window_json' => 'array',
            'interval_ms' => 'required|integer',
            'cooldown_s' => 'required|integer',
            'max_trades_json' => 'array',
            'enabled' => 'boolean',
        ]);

        $schedule = Schedule::create($data + [
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
        ]);

        $this->logAudit($request, 'schedule.create', $workspaceId, $data);
        ConfigVersioning::record($workspaceId, 'schedule.'.$schedule->id, $schedule->toArray(), null, $request->user()->id ?? null);
        return response()->json($schedule, 201);
    }

    public function update(Request $request, Schedule $schedule)
    {
        $data = $request->validate([
            'name' => 'sometimes|string',
            'window_json' => 'array',
            'interval_ms' => 'integer',
            'cooldown_s' => 'integer',
            'max_trades_json' => 'array',
            'enabled' => 'boolean',
        ]);
        $previous = $schedule->toArray();
        $schedule->update($data);
        $this->logAudit($request, 'schedule.update', $schedule->workspace_id, $data);
        ConfigVersioning::record($schedule->workspace_id, 'schedule.'.$schedule->id, $schedule->toArray(), $previous, $request->user()->id ?? null);
        return $schedule;
    }

    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return response()->json(['deleted' => true]);
    }

    public function simulate(Request $request, ScheduleGate $gate, Clock $clock)
    {
        $data = $request->validate([
            'schedule_id' => 'nullable|uuid|exists:schedules,id',
            'window_json' => 'array',
            'interval_ms' => 'nullable|integer',
            'cooldown_s' => 'nullable|integer',
            'max_trades_json' => 'array',
            'timezone' => 'nullable|string',
            'now' => 'nullable|date',
            'hours' => 'nullable|integer',
        ]);
        $schedule = isset($data['schedule_id'])
            ? Schedule::find($data['schedule_id'])
            : new Schedule([
                'window_json' => $data['window_json'] ?? [],
                'interval_ms' => $data['interval_ms'] ?? 60000,
                'cooldown_s' => $data['cooldown_s'] ?? 0,
                'max_trades_json' => $data['max_trades_json'] ?? [],
            ]);

        $now = $data['now'] ? \Carbon\CarbonImmutable::parse($data['now']) : $clock->now();
        $hours = $data['hours'] ?? 24;
        $times = $gate->nextEvaluationTimes($schedule, $now, $hours);
        return response()->json([
            'timezone' => $schedule->window_json['timezone'] ?? env('SCHEDULER_DEFAULT_TIMEZONE', 'Asia/Colombo'),
            'times' => $times,
        ]);
    }

    private function logAudit(Request $request, string $action, ?string $workspaceId = null, array $meta = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => $action,
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'metadata' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
