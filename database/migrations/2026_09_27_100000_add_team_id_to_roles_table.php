<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los roles personalizados pasan a pertenecer a un tenant.
 *
 * team_id NULL = rol de sistema/plataforma (viewer, monitorista, supervisor,
 * tenant_admin, …): catálogo global, sólo el super-admin lo modifica.
 * team_id NOT NULL = rol personalizado de ese tenant.
 *
 * Additive-only: el índice único global de `code` se conserva; los códigos de
 * roles personalizados se namespacean por tenant (`t{team_id}-{slug}`) para
 * que dos tenants puedan usar el mismo slug sin colisionar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
