<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->unique()->constrained('ai_event_evaluations')->cascadeOnDelete();
            $table->text('summary');
            $table->jsonb('reasoning_steps_json')->nullable();
            $table->jsonb('key_factors_json')->nullable();
            $table->jsonb('confidence_breakdown_json')->nullable();
            $table->jsonb('evidence_used_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_explanations');
    }
};
