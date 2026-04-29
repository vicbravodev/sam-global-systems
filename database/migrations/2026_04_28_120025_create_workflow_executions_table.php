<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->string('source_reference_id')->nullable();
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->unique(
                ['automation_workflow_id', 'source_type', 'source_reference_id'],
                'workflow_executions_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_executions');
    }
};
