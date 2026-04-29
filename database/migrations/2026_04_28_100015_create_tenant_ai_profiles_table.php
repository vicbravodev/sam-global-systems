<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ai_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('profile_code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('prompt_overrides_json')->nullable();
            $table->string('risk_tolerance');
            $table->string('false_positive_tolerance');
            $table->string('automation_level');
            $table->string('media_strategy');
            $table->jsonb('human_review_policy_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_profiles');
    }
};
