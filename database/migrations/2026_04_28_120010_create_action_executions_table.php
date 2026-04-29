<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('action_type');
            $table->string('source_type');
            $table->string('source_reference_id')->nullable();
            // SPEC-11-DEFERRED: incident_id FK lands when the Incidents domain ships.
            $table->unsignedBigInteger('incident_id')->nullable();
            // SPEC-10-DEFERRED: decision_id FK lands when the Decisions domain ships.
            $table->unsignedBigInteger('decision_id')->nullable();
            $table->foreignId('automation_workflow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('action_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->string('execution_mode');
            $table->string('target_type')->nullable();
            $table->string('target_reference')->nullable();
            $table->jsonb('payload_json')->nullable();
            $table->jsonb('response_json')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index('incident_id');
            $table->index('decision_id');
            $table->index(['source_type', 'source_reference_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_executions');
    }
};
