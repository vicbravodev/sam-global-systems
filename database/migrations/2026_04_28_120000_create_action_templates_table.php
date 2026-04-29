<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('action_type');
            $table->string('channel')->nullable();
            $table->string('subject_template')->nullable();
            $table->text('body_template')->nullable();
            $table->jsonb('parameters_schema_json')->nullable();
            $table->jsonb('config_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'code']);
            $table->index(['team_id', 'action_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_templates');
    }
};
