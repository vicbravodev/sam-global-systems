<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('kpi_code');
            $table->string('period_type');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('dimension_type')->nullable();
            $table->string('dimension_reference')->nullable();
            $table->decimal('value', 14, 4);
            $table->string('unit')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->index(['team_id', 'kpi_code', 'period_start']);
            $table->index(['team_id', 'dimension_type', 'dimension_reference']);
            $table->unique(
                ['team_id', 'kpi_code', 'period_type', 'period_start', 'dimension_type', 'dimension_reference'],
                'kpi_records_team_metric_period_dim_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_records');
    }
};
