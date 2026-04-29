<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ruleset_id')->constrained('rule_sets')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('scope');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->jsonb('conditions_json');
            $table->foreignId('outcome_override')->nullable()->constrained('decision_outcomes');
            $table->foreignId('escalation_policy_id')->nullable()->constrained('escalation_policies');
            $table->unsignedBigInteger('automation_action_id')->nullable();
            $table->boolean('stop_processing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['ruleset_id', 'priority']);
            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_rules');
    }
};
