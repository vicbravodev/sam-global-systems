<?php

namespace App\Domains\Incidents\Models;

use Database\Factories\Domains\Incidents\IncidentTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentType extends Model
{
    /** @use HasFactory<IncidentTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'default_priority_id',
        'is_active',
    ];

    /**
     * @return BelongsTo<IncidentPriority, $this>
     */
    public function defaultPriority(): BelongsTo
    {
        return $this->belongsTo(IncidentPriority::class, 'default_priority_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): IncidentTypeFactory
    {
        return IncidentTypeFactory::new();
    }
}
