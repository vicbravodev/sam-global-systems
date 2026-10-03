<?php

namespace App\Domains\Audit\Observers;

use App\Domains\Audit\Actions\RecordEntityChange;
use App\Domains\Audit\Enums\ChangeActorType;
use App\Domains\Audit\Enums\ChangeType;
use App\Support\SystemLog;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnitEnum;

/**
 * Observador de los modelos de `audit.tracked_changes` (Spec 14 §4.4): cuando
 * una escritura cambia alguno de sus campos significativos, guarda UNA fila de
 * `change_histories` con el antes/después de esos campos.
 *
 * - Sin campo rastreado cambiado no hace nada ni registra nada: es el camino
 *   caliente (telemetría, `last_seen_at`, escalada) y loguearlo sería ruido.
 * - La fila es del team de la ENTIDAD, nunca del tenant ambiente.
 * - El historial nunca rompe la escritura de negocio: va en su propio
 *   savepoint (si falla dentro de una transacción de Postgres no la deja
 *   abortada) y cualquier error queda en `audit.entity_change.record_failed`.
 */
class RecordTrackedChanges
{
    public function __construct(
        private readonly RecordEntityChange $recordEntityChange,
    ) {}

    public function updated(Model $model): void
    {
        $tracked = $this->trackedFields($model);
        $changedFields = array_values(array_filter(
            array_keys($tracked),
            static fn (string $field): bool => $model->wasChanged($field),
        ));

        if ($changedFields === []) {
            return;
        }

        $entityId = $model->getKey();
        $teamId = $model->getAttribute('team_id');
        $input = [
            'entity_type' => class_basename($model),
            'entity_id' => is_int($entityId) ? $entityId : null,
            'team_id' => is_int($teamId) ? $teamId : null,
            'changed_fields' => $changedFields,
        ];

        if (! is_int($teamId) || ! is_int($entityId)) {
            // Plantilla de plataforma (team_id null): no es historia de ningún
            // tenant y el endpoint de cambios sólo lista por team.
            SystemLog::skipped('audit.entity_change.skipped', reason: 'platform_row', input: $input);

            return;
        }

        $before = [];
        $after = [];

        foreach ($changedFields as $field) {
            $before[$field] = $this->plain($model->getOriginal($field));
            $after[$field] = $this->plain($model->getAttribute($field));
        }

        $userId = auth()->id();
        $changeType = ChangeType::tryFrom($tracked[$changedFields[0]]) ?? ChangeType::Updated;

        try {
            DB::transaction(fn () => $this->recordEntityChange->execute(
                entityType: $model->getMorphClass(),
                entityId: $entityId,
                changeType: $changeType,
                teamId: $teamId,
                changedByType: is_int($userId) ? ChangeActorType::User : ChangeActorType::System,
                changedById: is_int($userId) ? $userId : null,
                before: $before,
                after: $after,
                changedFields: $changedFields,
            ));
        } catch (Throwable $e) {
            SystemLog::degraded('audit.entity_change.record_failed', reason: 'write_failed', input: $input, error: $e);
        }
    }

    /**
     * @return array<string, string> campo => valor de `ChangeType`
     */
    private function trackedFields(Model $model): array
    {
        $map = config('audit.tracked_changes');
        $fields = is_array($map) ? ($map[$model::class] ?? []) : [];

        return is_array($fields)
            ? array_filter($fields, static fn (mixed $type, mixed $field): bool => is_string($field) && is_string($type), ARRAY_FILTER_USE_BOTH)
            : [];
    }

    /**
     * Valor JSON-plano: enums por su valor, fechas en ISO-8601, escalares tal
     * cual. Los campos rastreados son ids, estados y banderas; nada libre.
     */
    private function plain(mixed $value): int|float|string|bool|null
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->toIso8601String(),
            is_scalar($value) => $value,
            default => null,
        };
    }
}
