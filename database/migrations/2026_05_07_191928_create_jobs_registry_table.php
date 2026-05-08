<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs_registry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('job_type');
            $table->nullableMorphs('jobable');
            $table->string('status')->default('pending');
            $table->text('description')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'job_type']);
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs_registry');
    }
};
