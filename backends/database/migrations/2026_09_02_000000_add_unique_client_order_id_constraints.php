<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Closes a duplicate-order gap flagged in the AutoFinX Phase 1-B architecture
 * audit: orders.client_order_id and execution_attempts.provider_order_id
 * were both indexed but not unique, so a retried job or a worker-overlap
 * race could insert two rows referencing what should be the same exchange
 * order, and reconciliation could not tell them apart.
 *
 * Follows the same dedupe-then-constrain pattern as
 * 2026_09_01_000001_harden_ratio_bot_runtime_and_feed_status.php: any
 * existing duplicate rows are backed up (full row payload, JSON) before
 * being deleted, keeping the most recently updated row of each duplicate
 * set. NULL values are excluded from dedupe/constraint scope entirely —
 * a NULL client_order_id/provider_order_id (not yet assigned, e.g. a
 * pending attempt) is expected to repeat and must never be treated as a
 * duplicate or be constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'workspace_id')
            && Schema::hasColumn('orders', 'client_order_id')) {
            if (! Schema::hasTable('orders_client_order_id_dedupe_backups')) {
                Schema::create('orders_client_order_id_dedupe_backups', function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('order_id')->nullable()->index();
                    $table->uuid('workspace_id')->nullable()->index();
                    $table->string('client_order_id')->nullable();
                    $table->json('row_payload');
                    $table->timestamp('backed_up_at');
                });
            }

            $this->dedupeOrdersClientOrderId();

            if (! Schema::hasIndex('orders', 'orders_workspace_client_order_id_unique')) {
                Schema::table('orders', function (Blueprint $table): void {
                    $table->unique(['workspace_id', 'client_order_id'], 'orders_workspace_client_order_id_unique');
                });
            }
        }

        if (Schema::hasTable('execution_attempts')
            && Schema::hasColumn('execution_attempts', 'venue_id')
            && Schema::hasColumn('execution_attempts', 'provider_order_id')) {
            if (! Schema::hasTable('exec_attempt_order_dedupe_backups')) {
                Schema::create('exec_attempt_order_dedupe_backups', function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('execution_attempt_id')->nullable()->index();
                    $table->uuid('venue_id')->nullable()->index();
                    $table->string('provider_order_id')->nullable();
                    $table->json('row_payload');
                    $table->timestamp('backed_up_at');
                });
            }

            $this->dedupeExecutionAttemptsProviderOrderId();

            if (! Schema::hasIndex('execution_attempts', 'exec_attempts_venue_provider_order_id_unique')) {
                Schema::table('execution_attempts', function (Blueprint $table): void {
                    $table->unique(['venue_id', 'provider_order_id'], 'exec_attempts_venue_provider_order_id_unique');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            try {
                if (Schema::hasIndex('orders', 'orders_workspace_client_order_id_unique')) {
                    Schema::table('orders', function (Blueprint $table): void {
                        $table->dropUnique('orders_workspace_client_order_id_unique');
                    });
                }
            } catch (Throwable) {
                // Index may not exist on older rollback targets.
            }
        }

        if (Schema::hasTable('execution_attempts')) {
            try {
                if (Schema::hasIndex('execution_attempts', 'exec_attempts_venue_provider_order_id_unique')) {
                    Schema::table('execution_attempts', function (Blueprint $table): void {
                        $table->dropUnique('exec_attempts_venue_provider_order_id_unique');
                    });
                }
            } catch (Throwable) {
                // Index may not exist on older rollback targets.
            }
        }
    }

    private function dedupeOrdersClientOrderId(): void
    {
        $duplicates = DB::table('orders')
            ->whereNotNull('client_order_id')
            ->select('workspace_id', 'client_order_id', DB::raw('COUNT(*) as row_count'))
            ->groupBy('workspace_id', 'client_order_id')
            ->having('row_count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('orders')
                ->where('workspace_id', $duplicate->workspace_id)
                ->where('client_order_id', $duplicate->client_order_id)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->get();

            $keep = $rows->first();
            if (! $keep) {
                continue;
            }

            $deleteIds = $rows->skip(1)->pluck('id')->all();
            if ($deleteIds === []) {
                continue;
            }

            foreach ($rows->skip(1) as $row) {
                DB::table('orders_client_order_id_dedupe_backups')->insert([
                    'id' => (string) Str::uuid(),
                    'order_id' => $row->id ?? null,
                    'workspace_id' => $row->workspace_id ?? null,
                    'client_order_id' => $row->client_order_id ?? null,
                    'row_payload' => json_encode((array) $row),
                    'backed_up_at' => now(),
                ]);
            }

            DB::table('orders')->whereIn('id', $deleteIds)->delete();
        }
    }

    private function dedupeExecutionAttemptsProviderOrderId(): void
    {
        $duplicates = DB::table('execution_attempts')
            ->whereNotNull('venue_id')
            ->whereNotNull('provider_order_id')
            ->select('venue_id', 'provider_order_id', DB::raw('COUNT(*) as row_count'))
            ->groupBy('venue_id', 'provider_order_id')
            ->having('row_count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('execution_attempts')
                ->where('venue_id', $duplicate->venue_id)
                ->where('provider_order_id', $duplicate->provider_order_id)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->get();

            $keep = $rows->first();
            if (! $keep) {
                continue;
            }

            $deleteIds = $rows->skip(1)->pluck('id')->all();
            if ($deleteIds === []) {
                continue;
            }

            foreach ($rows->skip(1) as $row) {
                DB::table('exec_attempt_order_dedupe_backups')->insert([
                    'id' => (string) Str::uuid(),
                    'execution_attempt_id' => $row->id ?? null,
                    'venue_id' => $row->venue_id ?? null,
                    'provider_order_id' => $row->provider_order_id ?? null,
                    'row_payload' => json_encode((array) $row),
                    'backed_up_at' => now(),
                ]);
            }

            DB::table('execution_attempts')->whereIn('id', $deleteIds)->delete();
        }
    }
};
