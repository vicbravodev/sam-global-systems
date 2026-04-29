<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_type_id')->constrained('incident_types');
            $table->foreignId('incident_status_id')->constrained('incident_statuses');
            $table->foreignId('incident_priority_id')->constrained('incident_priorities');
            $table->string('source_type');
            $table->unsignedBigInteger('source_reference_id')->nullable();
            $table->foreignId('related_event_id')->nullable()->constrained('normalized_events')->nullOnDelete();
            // SPEC-10-DEFERRED: `decisions` table is owned by spec 10 (Decisions domain) which is
            // being implemented in parallel. Keep as plain unsignedBigInteger until that lands; the
            // FK constraint can be added later via a follow-up migration.
            $table->unsignedBigInteger('related_decision_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->string('title');
            $table->text('summary');
            $table->text('description')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('false_positive_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('created_by_type');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'incident_status_id']);
            $table->index(['team_id', 'opened_at']);
            $table->index('asset_id');
            $table->index('driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
