<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_inference_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('ai_event_evaluations')->cascadeOnDelete();
            $table->jsonb('input_snapshot_json')->nullable();
            $table->jsonb('output_json')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->unsignedTinyInteger('media_assets_count')->nullable();
            $table->decimal('cost_estimate', 8, 4)->nullable();
            $table->string('status');
            $table->timestamps();

            $table->index('evaluation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_inference_logs');
    }
};
