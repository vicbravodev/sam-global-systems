<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_recommended_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('ai_event_evaluations')->cascadeOnDelete();
            $table->string('action_type');
            $table->unsignedTinyInteger('priority');
            $table->jsonb('parameters_json')->nullable();
            $table->boolean('requires_confirmation')->default(false);
            $table->timestamps();

            $table->index('evaluation_id');
            $table->index(['evaluation_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_recommended_actions');
    }
};
