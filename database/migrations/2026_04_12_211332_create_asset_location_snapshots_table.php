<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_location_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('formatted_location')->nullable();
            $table->decimal('speed', 6, 2)->nullable();
            $table->smallInteger('heading')->nullable();
            $table->timestamp('recorded_at');
            $table->string('source');
            $table->json('geocoding_metadata_json')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_location_snapshots');
    }
};
