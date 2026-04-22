<?php

namespace App\Domains\Normalization\Models;

use Database\Factories\Domains\Normalization\EventSeverityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventSeverity extends Model
{
    /** @use HasFactory<EventSeverityFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'label',
        'level',
        'color',
        'response_sla_seconds',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'response_sla_seconds' => 'integer',
        ];
    }

    protected static function newFactory(): EventSeverityFactory
    {
        return EventSeverityFactory::new();
    }
}
