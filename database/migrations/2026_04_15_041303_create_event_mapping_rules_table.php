<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_mapping_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('integration_providers')->cascadeOnDelete();
            $table->string('external_event_type');
            $table->jsonb('external_conditions_json')->nullable();
            $table->foreignId('mapped_event_type_id')->constrained('event_types')->cascadeOnDelete();
            $table->foreignId('mapped_category_id')->nullable()->constrained('event_categories')->nullOnDelete();
            $table->foreignId('mapped_severity_id')->nullable()->constrained('event_severities')->nullOnDelete();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['provider_id', 'external_event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_mapping_rules');
    }
};
