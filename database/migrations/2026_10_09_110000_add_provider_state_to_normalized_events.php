<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un safety event de Samsara es una entidad con estado (`eventState`,
     * `behaviorLabels`): cada cambio llega como un raw event nuevo, pero debe
     * actualizar UNA fila de `normalized_events` (spec 2026-10-04, alertas 04).
     *
     * - `provider_event_key`: identidad del proveedor (`safety:{id}`), única por
     *   tenant. Null para todo lo que no es un safety event del feed (los null
     *   no chocan en el índice único).
     * - `provider_state`: último `eventState`; `superseded` marca filas viejas de
     *   un mismo evento que existían antes de este cambio.
     * - `provider_updated_at`: `updatedAtTime` del proveedor, para no pisar un
     *   estado nuevo con uno viejo que se reprocesa.
     * - `provider_dismissed_at`: cuándo se descartó en origen; deja de contar.
     */
    public function up(): void
    {
        Schema::table('normalized_events', function (Blueprint $table) {
            $table->string('provider_event_key', 191)->nullable()->after('raw_event_id');
            $table->string('provider_state', 32)->nullable()->after('status');
            $table->timestamp('provider_updated_at')->nullable()->after('provider_state');
            $table->timestamp('provider_dismissed_at')->nullable()->after('provider_updated_at');

            $table->unique(['team_id', 'provider_event_key']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('normalized_events', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'provider_event_key']);
            $table->dropColumn(['provider_event_key', 'provider_state', 'provider_updated_at', 'provider_dismissed_at']);
        });
    }

    /**
     * Safety events ya normalizados: la fila más reciente de cada evento se
     * queda la clave y el estado; las anteriores (si las hay) quedan
     * `superseded`, sin clave. No se borra nada: pueden tener incidentes.
     */
    private function backfill(): void
    {
        $rows = DB::table('normalized_events')
            ->join('raw_events', 'raw_events.id', '=', 'normalized_events.raw_event_id')
            ->where('raw_events.deduplication_key', 'like', 'safety:%')
            ->whereNotNull('raw_events.external_event_id')
            ->orderBy('normalized_events.id')
            ->get([
                'normalized_events.id',
                'normalized_events.team_id',
                'raw_events.id as raw_id',
                'raw_events.external_event_id',
                'raw_events.payload_json',
            ]);

        $latest = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload_json, true);
            $payload = is_array($payload) ? $payload : [];
            $group = $row->team_id.'|'.$row->external_event_id;
            $candidate = [
                'id' => $row->id,
                'raw_id' => $row->raw_id,
                'key' => 'safety:'.$row->external_event_id,
                'state' => is_string($payload['eventState'] ?? null) ? $payload['eventState'] : null,
                'updated_at' => self::time($payload['updatedAtTime'] ?? null),
            ];

            $current = $latest[$group] ?? null;

            if ($current === null || self::isNewer($candidate, $current)) {
                if ($current !== null) {
                    $this->supersede($current['id']);
                }

                $latest[$group] = $candidate;
            } else {
                $this->supersede($candidate['id']);
            }
        }

        foreach ($latest as $row) {
            DB::table('normalized_events')->where('id', $row['id'])->update([
                'provider_event_key' => $row['key'],
                'provider_state' => $row['state'],
                'provider_updated_at' => $row['updated_at'],
                'provider_dismissed_at' => $row['state'] === 'dismissed' ? ($row['updated_at'] ?? now()) : null,
            ]);
        }
    }

    private function supersede(int $id): void
    {
        DB::table('normalized_events')->where('id', $id)->update([
            'provider_event_key' => null,
            'provider_state' => 'superseded',
        ]);
    }

    /**
     * @param  array{raw_id: int, updated_at: ?string}  $a
     * @param  array{raw_id: int, updated_at: ?string}  $b
     */
    private static function isNewer(array $a, array $b): bool
    {
        if ($a['updated_at'] !== null && $b['updated_at'] !== null && $a['updated_at'] !== $b['updated_at']) {
            return $a['updated_at'] > $b['updated_at'];
        }

        return $a['raw_id'] > $b['raw_id'];
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
};
