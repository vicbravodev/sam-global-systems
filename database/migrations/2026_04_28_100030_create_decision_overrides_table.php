<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->constrained('decisions')->cascadeOnDelete();
            $table->foreignId('overridden_by_user_id')->constrained('users');
            $table->string('previous_outcome');
            $table->string('new_outcome');
            $table->text('reason');
            $table->timestamps();

            $table->index('decision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_overrides');
    }
};
