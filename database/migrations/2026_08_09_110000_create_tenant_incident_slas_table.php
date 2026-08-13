<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_incident_slas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_priority_id')->constrained('incident_priorities')->cascadeOnDelete();
            $table->unsignedInteger('sla_seconds')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'incident_priority_id']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_incident_slas');
    }
};
