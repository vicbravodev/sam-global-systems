<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_execution_id')->constrained()->cascadeOnDelete();
            $table->string('log_type');
            $table->text('message');
            $table->jsonb('payload_json')->nullable();
            $table->timestamps();

            $table->index('action_execution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_execution_logs');
    }
};
