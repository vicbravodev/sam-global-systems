<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_escalation_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('escalation_type');
            $table->jsonb('trigger_conditions_json');
            $table->jsonb('steps_json');
            $table->jsonb('time_constraints_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'escalation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_escalation_configs');
    }
};
