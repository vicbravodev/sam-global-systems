<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('device_type');
            $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
            $table->string('external_device_id')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('attached_at');
            $table->timestamp('detached_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_devices');
    }
};
