<?php

namespace App\Domains\Incidents\Models;

use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use Database\Factories\Domains\Incidents\IncidentEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentEvidence extends Model
{
    /** @use HasFactory<IncidentEvidenceFactory> */
    use HasFactory;

    protected $table = 'incident_evidence';

    protected $fillable = [
        'incident_id',
        'evidence_type',
        'source_type',
        'source_reference_id',
        'title',
        'description',
        'file_url',
        'storage_path',
        'metadata_json',
        'added_by_type',
        'added_by_id',
    ];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evidence_type' => EvidenceType::class,
            'source_type' => EvidenceSourceType::class,
            'added_by_type' => IncidentCreatorType::class,
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): IncidentEvidenceFactory
    {
        return IncidentEvidenceFactory::new();
    }
}
