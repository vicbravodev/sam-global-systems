<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_context_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('normalized_event_id')->unique()->constrained('normalized_events')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('profile_code')->nullable();
            $table->string('risk_level')->nullable();
            $table->decimal('priority_score', 5, 2)->nullable();
            $table->decimal('recurrence_score', 5, 2)->nullable();
            $table->jsonb('contextual_flags_json')->nullable();
            $table->jsonb('summary_json')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'risk_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_context_profiles');
    }
};
