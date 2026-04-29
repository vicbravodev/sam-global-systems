<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_definition_id')->constrained('report_definitions')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('requested_by_type');
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->jsonb('filters_json')->nullable();
            $table->string('status');
            $table->string('output_format');
            $table->string('file_path')->nullable();
            $table->jsonb('result_snapshot_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'report_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_executions');
    }
};
