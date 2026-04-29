<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_config_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('snapshot_json');
            $table->string('created_by_type');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'version']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_config_versions');
    }
};
