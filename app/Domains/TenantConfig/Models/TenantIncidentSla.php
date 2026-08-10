<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\TenantConfig\TenantIncidentSlaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantIncidentSla extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'incident_priority_id',
        'sla_seconds',
    ];

    protected function casts(): array
    {
        return [
            'sla_seconds' => 'integer',
        ];
    }

    protected static function newFactory(): TenantIncidentSlaFactory
    {
        return TenantIncidentSlaFactory::new();
    }
}
