<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_related_incident_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('relation_type');
            $table->decimal('confidence_score', 3, 2)->nullable();
            $table->timestamps();

            $table->unique(['normalized_event_id', 'incident_id', 'relation_type'], 'event_incident_link_unique');
            $table->index('team_id');
            $table->index(['normalized_event_id', 'relation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_related_incident_links');
    }
};
