<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            if (! Schema::hasColumn('ratio_bot_states', 'last_feed_event_at')) {
                $table->timestamp('last_feed_event_at')->nullable()->after('feed_status_updated_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'last_feed_healthy_at')) {
                $table->timestamp('last_feed_healthy_at')->nullable()->after('last_feed_event_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'feed_age_seconds')) {
                $table->integer('feed_age_seconds')->nullable()->after('last_feed_healthy_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'degradation_started_at')) {
                $table->timestamp('degradation_started_at')->nullable()->after('feed_age_seconds');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'reconnect_attempts')) {
                $table->integer('reconnect_attempts')->default(0)->after('degradation_started_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'consecutive_healthy_events')) {
                $table->integer('consecutive_healthy_events')->default(0)->after('reconnect_attempts');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'recovered_at')) {
                $table->timestamp('recovered_at')->nullable()->after('consecutive_healthy_events');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'blocker_class')) {
                $table->string('blocker_class', 24)->nullable()->after('recovered_at');
            }
            if (! Schema::hasColumn('ratio_bot_states', 'operator_action_required')) {
                $table->boolean('operator_action_required')->default(false)->after('blocker_class');
            }
        });

        if (Schema::hasTable('market_feed_status')
            && Schema::hasColumn('market_feed_status', 'workspace_id')
            && Schema::hasColumn('market_feed_status', 'symbol_id')
            && Schema::hasColumn('market_feed_status', 'exchange')
            && Schema::hasColumn('market_feed_status', 'market_type')) {
            if (! Schema::hasTable('market_feed_status_dedupe_backups')) {
                Schema::create('market_feed_status_dedupe_backups', function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                    $table->uuid('market_feed_status_id')->nullable()->index();
                    $table->uuid('workspace_id')->nullable()->index();
                    $table->uuid('symbol_id')->nullable()->index();
                    $table->string('exchange', 32)->nullable();
                    $table->string('market_type', 16)->nullable();
                    $table->json('row_payload');
                    $table->timestamp('backed_up_at');
                });
            }

            $this->dedupeMarketFeedStatus();

            if (! Schema::hasIndex('market_feed_status', 'mfs_workspace_symbol_exchange_type_unique')) {
                Schema::table('market_feed_status', function (Blueprint $table): void {
                    $table->unique(['workspace_id', 'symbol_id', 'exchange', 'market_type'], 'mfs_workspace_symbol_exchange_type_unique');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('market_feed_status')) {
            try {
                if (Schema::hasIndex('market_feed_status', 'mfs_workspace_symbol_exchange_type_unique')) {
                    Schema::table('market_feed_status', function (Blueprint $table): void {
                        $table->dropUnique('mfs_workspace_symbol_exchange_type_unique');
                    });
                }
            } catch (Throwable) {
                // Index may not exist on older rollback targets.
            }
        }

        Schema::table('ratio_bot_states', function (Blueprint $table): void {
            foreach ([
                'operator_action_required',
                'blocker_class',
                'recovered_at',
                'consecutive_healthy_events',
                'reconnect_attempts',
                'degradation_started_at',
                'feed_age_seconds',
                'last_feed_healthy_at',
                'last_feed_event_at',
            ] as $column) {
                if (Schema::hasColumn('ratio_bot_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function dedupeMarketFeedStatus(): void
    {
        $duplicates = DB::table('market_feed_status')
            ->select('workspace_id', 'symbol_id', 'exchange', 'market_type', DB::raw('COUNT(*) as row_count'))
            ->groupBy('workspace_id', 'symbol_id', 'exchange', 'market_type')
            ->having('row_count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('market_feed_status')
                ->where('workspace_id', $duplicate->workspace_id)
                ->where('symbol_id', $duplicate->symbol_id)
                ->where('exchange', $duplicate->exchange)
                ->where('market_type', $duplicate->market_type)
                ->orderByDesc('last_tick_at')
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->get();

            $keep = $rows->first();
            if (! $keep) {
                continue;
            }

            $deleteIds = $rows->skip(1)->pluck('id')->all();
            if ($deleteIds !== []) {
                foreach ($rows->skip(1) as $row) {
                    DB::table('market_feed_status_dedupe_backups')->insert([
                        'id' => (string) Str::uuid(),
                        'market_feed_status_id' => $row->id ?? null,
                        'workspace_id' => $row->workspace_id ?? null,
                        'symbol_id' => $row->symbol_id ?? null,
                        'exchange' => $row->exchange ?? null,
                        'market_type' => $row->market_type ?? null,
                        'row_payload' => json_encode((array) $row),
                        'backed_up_at' => now(),
                    ]);
                }

                DB::table('market_feed_status')->whereIn('id', $deleteIds)->delete();
            }
        }
    }
};
