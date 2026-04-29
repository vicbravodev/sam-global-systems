<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_evaluation_id')->nullable()->constrained('ai_event_evaluations')->nullOnDelete();
            $table->foreignId('ruleset_id')->nullable()->constrained('rule_sets')->nullOnDelete();
            $table->string('decision_code');
            $table->text('decision_reason')->nullable();
            $table->string('priority_level');
            $table->boolean('requires_human_review')->default(false);
            $table->boolean('is_automated')->default(true);
            $table->foreignId('escalation_policy_id')->nullable()->constrained('escalation_policies')->nullOnDelete();
            $table->foreignId('outcome_id')->constrained('decision_outcomes');
            $table->unsignedBigInteger('context_snapshot_id')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['team_id', 'decided_at']);
            $table->index('normalized_event_id');
            $table->unique('ai_evaluation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
