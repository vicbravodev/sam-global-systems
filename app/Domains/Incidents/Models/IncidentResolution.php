<?php

namespace App\Domains\Incidents\Models;

use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use Database\Factories\Domains\Incidents\IncidentResolutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentResolution extends Model
{
    /** @use HasFactory<IncidentResolutionFactory> */
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'resolution_code',
        'resolution_summary',
        'resolved_by_type',
        'resolved_by_id',
        'root_cause',
        'corrective_action',
        'preventive_action',
        'resolved_at',
        'metadata_json',
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
            'resolution_code' => ResolutionCode::class,
            'resolved_by_type' => IncidentCreatorType::class,
            'resolved_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): IncidentResolutionFactory
    {
        return IncidentResolutionFactory::new();
    }
}
