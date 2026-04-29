<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_schedule_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('profile_code');
            $table->string('timezone');
            $table->jsonb('operating_hours_json');
            $table->jsonb('holidays_json')->nullable();
            $table->jsonb('shift_rules_json')->nullable();
            $table->jsonb('after_hours_behavior_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'profile_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_schedule_profiles');
    }
};
