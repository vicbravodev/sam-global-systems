<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_media_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('ai_event_evaluations')->cascadeOnDelete();
            $table->foreignId('event_media_context_id')->constrained('event_media_contexts')->cascadeOnDelete();
            $table->string('media_type');
            $table->string('assessment_type');
            $table->string('result');
            $table->decimal('confidence_score', 3, 2)->nullable();
            $table->jsonb('extracted_signals_json')->nullable();
            $table->text('summary_text')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_estimate', 8, 4)->nullable();
            $table->string('model_used')->nullable();
            $table->timestamp('assessed_at');
            $table->timestamps();

            $table->index('evaluation_id');
            $table->index('event_media_context_id');
            $table->unique(['evaluation_id', 'event_media_context_id'], 'ai_media_assessments_eval_media_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_media_assessments');
    }
};
