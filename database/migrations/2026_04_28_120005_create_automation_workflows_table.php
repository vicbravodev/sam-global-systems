<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger_type');
            $table->jsonb('trigger_conditions_json')->nullable();
            $table->string('status');
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('steps_json');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'trigger_type', 'is_active']);
            $table->index(['team_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflows');
    }
};
