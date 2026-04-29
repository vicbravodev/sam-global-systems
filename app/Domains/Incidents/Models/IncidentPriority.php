<?php

namespace App\Domains\Incidents\Models;

use Database\Factories\Domains\Incidents\IncidentPriorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentPriority extends Model
{
    /** @use HasFactory<IncidentPriorityFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'level',
        'sla_seconds',
        'color',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'sla_seconds' => 'integer',
        ];
    }

    protected static function newFactory(): IncidentPriorityFactory
    {
        return IncidentPriorityFactory::new();
    }
}
