<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Human-in-the-loop label: the operator's verdict on the AI evaluation
     * (confirmed | false_positive). Additive only.
     */
    public function up(): void
    {
        Schema::table('ai_event_evaluations', function (Blueprint $table) {
            $table->string('operator_verdict')->nullable();
            $table->foreignId('operator_verdict_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('operator_verdict_at')->nullable();
            $table->text('operator_verdict_note')->nullable();
            $table->index(['team_id', 'operator_verdict']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_event_evaluations', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'operator_verdict']);
            $table->dropConstrainedForeignId('operator_verdict_by');
            $table->dropColumn(['operator_verdict', 'operator_verdict_at', 'operator_verdict_note']);
        });
    }
};
