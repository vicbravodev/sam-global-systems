<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('normalized_event_id')->constrained('normalized_events')->cascadeOnDelete();
            $table->string('relation_type');
            $table->timestamps();

            $table->unique(['incident_id', 'normalized_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_event_links');
    }
};
