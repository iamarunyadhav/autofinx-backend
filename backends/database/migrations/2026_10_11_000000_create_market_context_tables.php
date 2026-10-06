<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market Context (docs/MARKET_CONTEXT_PLAN.md). Global public data, NOT
 * workspace-scoped. Every instant is a dateTime holding UTC wall time (models
 * read them with App\Casts\UtcDateTime). dateTime(), never timestamp(): on the
 * production MariaDB a first TIMESTAMP NOT NULL gets ON UPDATE CURRENT_TIMESTAMP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mctx_events')) {
            Schema::create('mctx_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('dedupe_key', 64)->unique();          // sha256(source|external_id or normalized title)
                $table->string('source', 48);
                $table->string('external_id', 191)->nullable();
                $table->string('title', 300);
                $table->string('excerpt', 600)->nullable();          // bounded, purged after retention.excerpt_days
                $table->string('url', 500)->nullable();
                $table->string('category', 40);
                $table->string('severity', 10);
                $table->string('classifier_version', 24)->nullable();
                $table->dateTime('published_at');                    // the source's claim
                $table->dateTime('first_seen_at');                   // when we stored it; backtests filter on this
                $table->string('raw_hash', 64);                      // sha256 of the raw title+excerpt
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->index('first_seen_at', 'mctx_events_first_seen');
                $table->index(['source', 'published_at'], 'mctx_events_source_pub');
            });
        }

        if (! Schema::hasTable('mctx_event_instruments')) {
            Schema::create('mctx_event_instruments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id');
                $table->string('asset_class', 12);
                $table->string('instrument', 24);
                $table->decimal('relevance', 4, 3);
                // Denormalised from the event so the per-instrument as-of query needs no join to filter.
                $table->dateTime('published_at');
                $table->dateTime('first_seen_at');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['event_id', 'asset_class', 'instrument'], 'mctx_ei_unique');
                $table->index(['asset_class', 'instrument', 'first_seen_at'], 'mctx_ei_asof');
                $table->foreign('event_id')->references('id')->on('mctx_events')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('mctx_metrics')) {
            Schema::create('mctx_metrics', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('source', 48);
                $table->string('asset_class', 12);
                $table->string('instrument', 24);                    // 'ALL' = market-wide for the asset class
                $table->string('metric', 48);
                $table->decimal('value', 30, 8);
                $table->dateTime('observed_at');
                $table->dateTime('first_seen_at');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['source', 'instrument', 'metric', 'observed_at'], 'mctx_metrics_unique');
                $table->index(['asset_class', 'instrument', 'metric', 'observed_at'], 'mctx_metrics_series');
                $table->index('first_seen_at', 'mctx_metrics_first_seen');
            });
        }

        if (! Schema::hasTable('mctx_snapshots')) {
            Schema::create('mctx_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('asset_class', 12);
                $table->string('instrument', 24);
                $table->dateTime('as_of');                           // bucket boundary
                $table->unsignedSmallInteger('schema_version');
                $table->json('payload');
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['asset_class', 'instrument', 'as_of', 'schema_version'], 'mctx_snapshots_unique');
                $table->index(['instrument', 'as_of'], 'mctx_snapshots_latest');
            });
        }

        if (! Schema::hasTable('mctx_source_health')) {
            Schema::create('mctx_source_health', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('source', 48)->unique();
                $table->string('status', 16)->default('NEW');        // NEW|OK|DEGRADED|FAILING|OPEN
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('last_success_at')->nullable();
                $table->dateTime('next_due_at')->nullable();
                $table->dateTime('disabled_until')->nullable();      // circuit breaker
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->string('last_error_code', 64)->nullable();
                $table->string('etag', 191)->nullable();
                $table->string('last_modified', 64)->nullable();
                $table->string('last_content_hash', 64)->nullable();
                $table->unsignedInteger('last_item_count')->default(0);
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mctx_event_instruments');
        Schema::dropIfExists('mctx_events');
        Schema::dropIfExists('mctx_metrics');
        Schema::dropIfExists('mctx_snapshots');
        Schema::dropIfExists('mctx_source_health');
    }
};
