<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->constrained('decisions')->cascadeOnDelete();
            $table->string('rule_code')->nullable();
            $table->string('source_type');
            $table->unsignedBigInteger('source_reference_id')->nullable();
            $table->unsignedTinyInteger('step_order');
            $table->jsonb('input_fragment_json')->nullable();
            $table->jsonb('output_fragment_json')->nullable();
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->index(['decision_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_traces');
    }
};
