<?php

namespace App\Domains\Audit\Actions;

use App\Domains\Audit\Enums\ChangeActorType;
use App\Domains\Audit\Enums\ChangeType;
use App\Domains\Audit\Models\ChangeHistory;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Persists a before/after snapshot for a critical entity mutation.
 * Used by Eloquent observers attached to auditable models.
 */
class RecordEntityChange
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<int, string>|null  $changedFields
     */
    public function execute(
        string $entityType,
        int $entityId,
        ChangeType $changeType,
        ?int $teamId = null,
        ChangeActorType $changedByType = ChangeActorType::System,
        ?int $changedById = null,
        ?array $before = null,
        ?array $after = null,
        ?array $changedFields = null,
        ?string $reason = null,
        ?DateTimeInterface $occurredAt = null,
    ): ChangeHistory {
        $history = new ChangeHistory;
        $history->team_id = $teamId;
        $history->entity_type = $entityType;
        $history->entity_id = $entityId;
        $history->changed_by_type = $changedByType;
        $history->changed_by_id = $changedById;
        $history->change_type = $changeType;
        $history->before_json = $before;
        $history->after_json = $after;
        $history->changed_fields_json = $changedFields;
        $history->reason = $reason;
        $history->occurred_at = $occurredAt ? Carbon::instance($occurredAt) : Carbon::now();
        $history->save();

        return $history;
    }
}
