<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('evidence_type');
            $table->string('source_type');
            $table->unsignedBigInteger('source_reference_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('file_url')->nullable();
            $table->string('storage_path')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->string('added_by_type');
            $table->unsignedBigInteger('added_by_id')->nullable();
            $table->timestamps();

            $table->index('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_evidence');
    }
};
