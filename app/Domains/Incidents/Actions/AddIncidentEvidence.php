<?php

namespace App\Domains\Incidents\Actions;

use App\Contracts\ObjectStorage;
use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEvidence;
use Illuminate\Http\UploadedFile;

class AddIncidentEvidence
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly ObjectStorage $objectStorage,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function execute(
        Incident $incident,
        EvidenceType $evidenceType,
        EvidenceSourceType $sourceType,
        ?int $sourceReferenceId = null,
        ?string $title = null,
        ?string $description = null,
        ?array $metadata = null,
        ?UploadedFile $file = null,
        IncidentCreatorType $addedByType = IncidentCreatorType::System,
        ?int $addedById = null,
    ): IncidentEvidence {
        $storagePath = null;
        $fileUrl = null;

        if ($file !== null) {
            $storagePath = sprintf(
                '%d/incidents/%d/evidence/%s',
                $incident->team_id,
                $incident->id,
                uniqid().'-'.$file->getClientOriginalName(),
            );

            $this->objectStorage->put(
                $storagePath,
                (string) $file->get(),
                ['ContentType' => (string) $file->getMimeType()],
            );

            $fileUrl = $this->objectStorage->temporaryUrl($storagePath, now()->addHour());
        }

        $evidence = IncidentEvidence::query()->create([
            'incident_id' => $incident->id,
            'evidence_type' => $evidenceType,
            'source_type' => $sourceType,
            'source_reference_id' => $sourceReferenceId,
            'title' => $title,
            'description' => $description,
            'file_url' => $fileUrl,
            'storage_path' => $storagePath,
            'metadata_json' => $metadata,
            'added_by_type' => $addedByType,
            'added_by_id' => $addedById,
        ]);

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::EvidenceAdded,
            actorType: $addedByType === IncidentCreatorType::User ? TimelineActorType::User : TimelineActorType::System,
            actorId: $addedById,
            title: 'Evidencia adjuntada: '.($title ?? $evidenceType->value),
            payload: [
                'evidence_id' => $evidence->id,
                'evidence_type' => $evidenceType->value,
                'source_type' => $sourceType->value,
            ],
        );

        return $evidence;
    }
}
