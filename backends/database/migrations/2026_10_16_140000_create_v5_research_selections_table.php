<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('v5_research_selections', function(Blueprint $t){$t->uuid('id')->primary();$t->unsignedBigInteger('user_id')->index();$t->string('workspace_id')->default('default');$t->string('symbol');$t->string('side',20);$t->string('status',20)->default('TRACKING');$t->timestamp('selected_at');$t->timestamp('expires_at')->nullable();$t->decimal('selection_price',30,10)->nullable();$t->uuid('prediction_snapshot_id')->nullable();$t->unsignedInteger('original_rank')->nullable();$t->unsignedInteger('current_rank')->nullable();$t->string('horizon',20)->default('24h');$t->text('notes')->nullable();$t->json('research_metrics')->nullable();$t->timestamps();$t->index(['user_id','workspace_id','status']);}); }
 public function down(): void { Schema::dropIfExists('v5_research_selections'); }
};
