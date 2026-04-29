<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step_order');
            $table->string('step_type');
            $table->string('target_type')->nullable();
            $table->string('target_reference')->nullable();
            $table->unsignedInteger('delay_seconds')->nullable();
            $table->jsonb('conditions_json')->nullable();
            $table->string('fallback_action')->nullable();
            $table->timestamps();

            $table->index(['automation_workflow_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_steps');
    }
};
