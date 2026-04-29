<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('changed_by_type');
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->string('change_type');
            $table->jsonb('before_json')->nullable();
            $table->jsonb('after_json')->nullable();
            $table->jsonb('changed_fields_json')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            // Append-only: only created_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id', 'occurred_at']);
            $table->index(['team_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_histories');
    }
};
