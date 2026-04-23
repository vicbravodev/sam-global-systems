<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_event_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('evaluation_version')->default(1);
            $table->string('evaluation_mode');
            $table->string('classification');
            $table->decimal('confidence_score', 3, 2)->nullable();
            $table->decimal('risk_score', 3, 2)->nullable();
            $table->string('priority_level');
            $table->boolean('is_real_event')->nullable();
            $table->boolean('requires_action')->default(false);
            $table->string('recommended_action')->nullable();
            $table->text('explanation_text')->nullable();
            $table->jsonb('signals_json')->nullable();
            $table->jsonb('evidence_summary_json')->nullable();
            $table->string('model_used')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->unique(['normalized_event_id', 'evaluation_version']);
            $table->index(['team_id', 'evaluated_at']);
            $table->index('normalized_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_event_evaluations');
    }
};
