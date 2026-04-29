<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('entry_type');
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->jsonb('payload_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['incident_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_timelines');
    }
};
