<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Monitoreo HOS PR 2 en entornos ya sembrados (producción sólo corre
 * migraciones): los tipos de evento `hos_limit_exceeded` y `hos_unattended`
 * (propios del monitor; `hos_violation` de Samsara no se toca), el tipo de incidente
 * `hos_compliance` y la regla `hos-incident` en el ruleset propio, activo y
 * por defecto de cada tenant (el pack de ApplyDefaultTenantConfig no toca
 * rulesets existentes). Idempotente; en una base sin catálogo (instalación
 * nueva, tests) no hace nada: ahí lo siembran los seeders y el pack.
 * Literales a propósito: la migración no depende del código que cambie.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $category = DB::table('event_categories')->where('code', 'compliance')->value('id');
        $severity = DB::table('event_severities')->where('code', 'high')->value('id');

        $eventTypes = [
            'hos_limit_exceeded' => 'Horas de servicio rebasadas',
            'hos_unattended' => 'Horas de servicio sin atender',
        ];

        foreach ($eventTypes as $code => $name) {
            if ($category === null || DB::table('event_types')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('event_types')->insert([
                'code' => $code,
                'name' => $name,
                'description' => null,
                'category_id' => $category,
                'default_severity_id' => $severity,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $high = DB::table('incident_priorities')->where('code', 'high')->value('id');

        if ($high !== null && ! DB::table('incident_types')->where('code', 'hos_compliance')->exists()) {
            DB::table('incident_types')->insert([
                'code' => 'hos_compliance',
                'name' => 'Horas de servicio (HOS)',
                'description' => null,
                'default_priority_id' => $high,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $incident = DB::table('decision_outcomes')->where('code', 'INCIDENT')->value('id');

        if ($incident === null) {
            return;
        }

        $ruleSets = DB::table('rule_sets')
            ->whereNotNull('team_id')
            ->where('is_default', true)
            ->where('is_active', true)
            ->get(['id', 'team_id']);

        foreach ($ruleSets as $ruleSet) {
            if (DB::table('decision_rules')->where('ruleset_id', $ruleSet->id)->where('code', 'hos-incident')->exists()) {
                continue;
            }

            DB::table('decision_rules')->insert([
                'team_id' => $ruleSet->team_id,
                'ruleset_id' => $ruleSet->id,
                'code' => 'hos-incident',
                'name' => 'Horas de servicio → incidente',
                'description' => 'Una infracción de horas de servicio, o un chofer que no corrigió tras los avisos de SAM, abre un incidente para tu equipo de monitoreo.',
                'scope' => 'event_type',
                'priority' => 95,
                'conditions_json' => json_encode(['all' => [['field' => 'event_type_code', 'operator' => 'in', 'value' => ['hos_limit_exceeded', 'hos_unattended']]]]),
                'outcome_override' => $incident,
                'stop_processing' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Tipos de evento e incidente se quedan: pueden tener filas colgando.
        DB::table('decision_rules')->where('code', 'hos-incident')->delete();
    }
};
