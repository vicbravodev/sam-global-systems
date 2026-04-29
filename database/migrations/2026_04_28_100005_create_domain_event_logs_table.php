<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_name');
            $table->string('aggregate_type')->nullable();
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->jsonb('payload_json')->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('correlation_id')->nullable();
            $table->uuid('causation_id')->nullable();
            // Append-only: only created_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'occurred_at']);
            $table->index('correlation_id');
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('event_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_event_logs');
    }
};
