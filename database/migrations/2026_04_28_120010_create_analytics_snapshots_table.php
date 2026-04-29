<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('snapshot_type');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->jsonb('snapshot_json');
            $table->timestamps();

            $table->index(['team_id', 'snapshot_type', 'period_start']);
            $table->unique(
                ['team_id', 'snapshot_type', 'entity_type', 'entity_id', 'period_start'],
                'analytics_snapshots_team_type_entity_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_snapshots');
    }
};
