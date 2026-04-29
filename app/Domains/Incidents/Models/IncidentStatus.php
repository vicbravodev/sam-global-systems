<?php

namespace App\Domains\Incidents\Models;

use Database\Factories\Domains\Incidents\IncidentStatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentStatus extends Model
{
    /** @use HasFactory<IncidentStatusFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_terminal',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_terminal' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function newFactory(): IncidentStatusFactory
    {
        return IncidentStatusFactory::new();
    }
}
