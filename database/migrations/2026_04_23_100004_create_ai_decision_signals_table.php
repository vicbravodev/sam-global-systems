<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_decision_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('ai_event_evaluations')->cascadeOnDelete();
            $table->string('signal_code');
            $table->string('signal_value');
            $table->decimal('weight', 3, 2)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('evaluation_id');
            $table->index('signal_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decision_signals');
    }
};
