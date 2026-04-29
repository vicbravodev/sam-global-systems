<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_traces', function (Blueprint $table) {
            $table->id();
            $table->uuid('trace_id');
            $table->uuid('span_id');
            $table->uuid('parent_span_id')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module_name');
            $table->string('operation_name');
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->jsonb('input_reference_json')->nullable();
            $table->jsonb('output_reference_json')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata_json')->nullable();
            // Append-only: only created_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('trace_id');
            $table->index(['team_id', 'module_name']);
            $table->index('span_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_traces');
    }
};
