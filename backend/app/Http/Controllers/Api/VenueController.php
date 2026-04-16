<?php

namespace App\Http\Controllers\Api;

use App\Domain\Advanced\VenueManager;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\VenueConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VenueController extends Controller
{
    public function index()
    {
        return Venue::all();
    }

    public function connections(Request $request)
    {
        $workspaceId = $request->input('workspace_id');
        return VenueConnection::with('venue')->where('workspace_id', $workspaceId)->get();
    }

    public function storeConnection(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'venue_id' => 'required|uuid|exists:venues,id',
            'label' => 'nullable|string',
            'api_key' => 'nullable|string',
            'api_secret' => 'nullable|string',
            'passphrase' => 'nullable|string',
            'sandbox_mode' => 'boolean',
        ]);

        $conn = VenueConnection::updateOrCreate(
            ['workspace_id' => $data['workspace_id'], 'venue_id' => $data['venue_id']],
            [
                'id' => (string) Str::uuid(),
                'label' => $data['label'] ?? null,
                'api_key_encrypted' => $data['api_key'] ?? null,
                'api_secret_encrypted' => $data['api_secret'] ?? null,
                'passphrase_encrypted' => $data['passphrase'] ?? null,
                'sandbox_mode' => $data['sandbox_mode'] ?? true,
                'enabled' => false,
            ]
        );

        return response()->json($conn->fresh('venue'), 201);
    }

    public function toggle(VenueConnection $connection)
    {
        $connection->enabled = ! $connection->enabled;
        $connection->save();
        return response()->json($connection->fresh('venue'));
    }

    public function test(VenueConnection $connection, VenueManager $manager)
    {
        $status = $manager->testConnection($connection);
        return response()->json($status);
    }
}
