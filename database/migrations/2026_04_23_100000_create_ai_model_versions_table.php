<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('version');
            $table->string('model_type');
            $table->string('provider')->nullable();
            $table->jsonb('modality_support_json')->nullable();
            $table->jsonb('config_json')->nullable();
            $table->timestamp('deployed_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'version']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_versions');
    }
};
