<?php

namespace App\Domains\Incidents\Models;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use Database\Factories\Domains\Incidents\IncidentAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAssignment extends Model
{
    /** @use HasFactory<IncidentAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'assigned_to_type',
        'assigned_to_id',
        'role',
        'assigned_at',
        'unassigned_at',
        'assigned_by_type',
        'assigned_by_id',
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
            'assigned_to_type' => AssigneeType::class,
            'assigned_by_type' => IncidentCreatorType::class,
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): IncidentAssignmentFactory
    {
        return IncidentAssignmentFactory::new();
    }
}
