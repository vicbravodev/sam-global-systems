<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\CommentVisibility;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentComment;
use App\Models\User;

class AddIncidentComment
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    public function execute(
        Incident $incident,
        User $user,
        string $comment,
        CommentVisibility $visibility = CommentVisibility::TenantVisible,
    ): IncidentComment {
        $record = IncidentComment::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'comment' => $comment,
            'visibility' => $visibility,
        ]);

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::CommentAdded,
            actorType: TimelineActorType::User,
            actorId: $user->id,
            title: 'Comentario agregado',
            description: $comment,
            payload: [
                'comment_id' => $record->id,
                'visibility' => $visibility->value,
            ],
        );

        return $record;
    }
}
