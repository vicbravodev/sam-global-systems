<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las reglas sembradas de `AlertIncident` reconocían el disparador por el
     * texto de la PRIMERA condición (`data.conditions.0.description`). Pasan a
     * reconocerlo por `triggerId` en cualquier condición, igual que el poll de
     * respaldo (spec 2026-10-04, alertas 01). Sólo se convierten las reglas que
     * siguen exactamente como se sembraron: una regla que un operador cambió se
     * respeta. Idempotente.
     *
     * @var array<string, array{from: array<string, string>, to: array<string, int|string>, priority: int}>
     */
    private const array CONVERSIONS = [
        'panic_button' => [
            'from' => ['data.conditions.0.description' => 'Panic Button'],
            'to' => ['data.conditions.*.triggerId' => 1034],
            'priority' => 20,
        ],
        'tampering' => [
            'from' => ['data.conditions.0.description' => 'Tampering'],
            'to' => ['data.conditions.*.triggerId' => 1045],
            'priority' => 15,
        ],
        'camera_obstructed' => [
            'from' => ['data.conditions.0.description' => 'Camera Obstructed'],
            'to' => ['data.conditions.*.description' => 'Camera Obstructed'],
            'priority' => 10,
        ],
    ];

    public function up(): void
    {
        $this->convert(fn (array $conversion): array => [$conversion['from'], $conversion['to'], $conversion['priority']]);
    }

    public function down(): void
    {
        $this->convert(fn (array $conversion): array => [$conversion['to'], $conversion['from'], 10]);
    }

    /**
     * @param  Closure(array{from: array<string, string>, to: array<string, int|string>, priority: int}): array{0: array<string, mixed>, 1: array<string, mixed>, 2: int}  $direction
     */
    private function convert(Closure $direction): void
    {
        $rules = DB::table('event_mapping_rules')
            ->join('event_types', 'event_types.id', '=', 'event_mapping_rules.mapped_event_type_id')
            ->where('event_mapping_rules.external_event_type', 'AlertIncident')
            ->whereIn('event_types.code', array_keys(self::CONVERSIONS))
            ->get(['event_mapping_rules.id', 'event_mapping_rules.external_conditions_json', 'event_types.code']);

        foreach ($rules as $rule) {
            [$from, $to, $priority] = $direction(self::CONVERSIONS[$rule->code]);
            $current = json_decode((string) $rule->external_conditions_json, true);

            if ($current !== $from) {
                continue;
            }

            DB::table('event_mapping_rules')->where('id', $rule->id)->update([
                'external_conditions_json' => json_encode($to),
                'priority' => $priority,
                'updated_at' => now(),
            ]);
        }
    }
};
