<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_shadow_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_event_evaluation_id')->constrained('ai_event_evaluations')->cascadeOnDelete();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->string('model', 32);
            $table->unsignedSmallInteger('schema_version');
            $table->string('source', 16);
            $table->string('status', 16);
            $table->string('classification', 32)->nullable();
            $table->json('classification_probabilities_json')->nullable();
            $table->decimal('risk_score', 3, 2)->nullable();
            $table->decimal('needs_human_probability', 4, 3)->nullable();
            $table->json('media_answers_json')->nullable();
            $table->unsignedSmallInteger('images_sent')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('cost_estimate', 8, 5)->nullable();
            $table->string('error_code', 64)->nullable();
            // Fallo transitorio (429, 5xx, timeout): el siguiente job o backfill lo reintenta.
            $table->boolean('retryable')->default(false);
            $table->timestamps();

            $table->index('team_id');
            $table->index('normalized_event_id');
            $table->unique(['ai_event_evaluation_id', 'model', 'schema_version'], 'ai_shadow_eval_model_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_shadow_evaluations');
    }
};
