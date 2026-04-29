<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_rule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('base_rule_code');
            $table->string('override_type');
            $table->jsonb('override_config_json');
            $table->text('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'base_rule_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_rule_overrides');
    }
};
