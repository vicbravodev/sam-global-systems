<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('setting_key');
            $table->string('setting_group');
            $table->jsonb('value_json');
            $table->string('value_type');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('updated_by_type');
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'setting_key']);
            $table->index(['team_id', 'setting_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
