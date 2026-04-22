<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('normalized_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_event_id')->unique()->constrained('raw_events')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('integration_providers')->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('event_type_id')->constrained('event_types')->cascadeOnDelete();
            $table->foreignId('event_category_id')->constrained('event_categories')->cascadeOnDelete();
            $table->foreignId('event_severity_id')->constrained('event_severities')->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at');
            $table->jsonb('payload_normalized_json');
            $table->jsonb('context_json')->nullable();
            $table->string('status')->default('normalized');
            $table->timestamps();

            $table->index(['team_id', 'occurred_at']);
            $table->index(['team_id', 'event_type_id']);
            $table->index(['asset_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('normalized_events');
    }
};
