<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('assigned_to_type');
            $table->unsignedBigInteger('assigned_to_id');
            $table->string('role')->nullable();
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->string('assigned_by_type');
            $table->unsignedBigInteger('assigned_by_id')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'unassigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_assignments');
    }
};
