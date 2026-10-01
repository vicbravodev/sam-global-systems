<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_brandings', function (Blueprint $table) {
            $table->id();
            // El índice único de team_id lo crea 2026_09_27_120000_add_unique_team_index_to_tenant_brandings_table.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('display_name')->nullable();
            $table->text('email_signature')->nullable();
            $table->string('custom_domain')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_brandings');
    }
};
