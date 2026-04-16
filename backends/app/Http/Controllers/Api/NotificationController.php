<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $unreadOnly = filter_var($request->query('unread_only'), FILTER_VALIDATE_BOOLEAN);
        $limit = (int) $request->query('limit', 50);

        $query = Notification::query()->orderByDesc('created_at');

        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }
        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return response()->json([
            'notifications' => $query->limit($limit)->get(),
        ]);
    }

    public function unreadCount(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $count = Notification::where('workspace_id', $workspaceId)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markRead(Request $request)
    {
        $ids = $request->input('notification_ids', []);
        Notification::whereIn('id', $ids)->update(['read_at' => now()]);
        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request)
    {
        $workspaceId = $request->input('workspace_id');
        Notification::where('workspace_id', $workspaceId)->update(['read_at' => now()]);
        return response()->json(['success' => true]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'type' => 'required|string',
            'content' => 'required|array',
        ]);

        $notification = Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $data['workspace_id'],
            'type' => $data['type'],
            'content' => $data['content'],
        ]);

        return response()->json(['notification' => $notification], 201);
    }
}
