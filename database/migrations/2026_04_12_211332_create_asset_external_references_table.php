<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_external_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('integration_providers')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('external_type')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_external_references');
    }
};
