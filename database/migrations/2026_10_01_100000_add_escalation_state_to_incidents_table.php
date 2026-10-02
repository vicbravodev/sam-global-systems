<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado persistido de la escalera de SLA. Antes vivía sólo en el payload del
 * job diferido: un job perdido (Valkey reiniciado, `horizon:clear`, reintentos
 * agotados) apagaba la escalera en silencio. Con el estado en la fila, el
 * barrido (SweepOverdueEscalationsJob) re-despacha cualquier paso vencido.
 *
 * - `escalation_epoch`: generación de la escalera; se incrementa cada vez que
 *   se re-arma (creación, release, prioridad elevada). Un job de una
 *   generación anterior se descarta y las claves de aviso cambian.
 * - `escalation_level` / `escalation_attempt`: próximo paso por disparar.
 * - `next_escalation_at`: cuándo vence ese paso; null = nada pendiente.
 * - `escalation_exhausted_at`: se agotaron los niveles sin que nadie atendiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedInteger('escalation_epoch')->default(0)->after('claimed_at');
            $table->unsignedSmallInteger('escalation_level')->default(0)->after('escalation_epoch');
            $table->unsignedSmallInteger('escalation_attempt')->default(1)->after('escalation_level');
            $table->timestamp('next_escalation_at')->nullable()->after('escalation_attempt');
            $table->timestamp('escalation_exhausted_at')->nullable()->after('next_escalation_at');
            // El barrido cruza tenants a propósito: busca por vencimiento.
            $table->index('next_escalation_at');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['next_escalation_at']);
            $table->dropColumn([
                'escalation_epoch',
                'escalation_level',
                'escalation_attempt',
                'next_escalation_at',
                'escalation_exhausted_at',
            ]);
        });
    }
};
