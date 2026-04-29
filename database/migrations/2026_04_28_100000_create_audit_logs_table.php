<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('category')->nullable();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_reference_id')->nullable();
            $table->string('signature')->nullable();
            $table->text('summary');
            $table->jsonb('metadata_json')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('occurred_at');
            // Append-only: only created_at; no updated_at column.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'occurred_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('category');
            $table->unique(['team_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
