<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('report_type');
            $table->jsonb('data_sources_json')->nullable();
            $table->jsonb('filters_schema_json')->nullable();
            $table->jsonb('metrics_json')->nullable();
            $table->jsonb('visualization_config_json')->nullable();
            $table->jsonb('schedule_config_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
