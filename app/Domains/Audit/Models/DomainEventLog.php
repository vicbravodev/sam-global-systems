<?php

namespace App\Domains\Audit\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Audit\Support\AppendOnly;
use Database\Factories\Domains\Audit\DomainEventLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DomainEventLog extends Model
{
    /** @use HasFactory<DomainEventLogFactory> */
    use AppendOnly, BelongsToTenant, HasFactory;

    protected $table = 'domain_event_logs';

    protected $fillable = [
        'team_id',
        'event_name',
        'aggregate_type',
        'aggregate_id',
        'payload_json',
        'occurred_at',
        'correlation_id',
        'causation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DomainEventLogFactory
    {
        return DomainEventLogFactory::new();
    }
}
