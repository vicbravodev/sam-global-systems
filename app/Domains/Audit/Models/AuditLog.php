<?php

namespace App\Domains\Audit\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Audit\Support\AppendOnly;
use Database\Factories\Domains\Audit\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use AppendOnly, BelongsToTenant, HasFactory;

    protected $table = 'audit_logs';

    protected $fillable = [
        'team_id',
        'actor_type',
        'actor_id',
        'action',
        'category',
        'entity_type',
        'entity_id',
        'source_type',
        'source_reference_id',
        'signature',
        'summary',
        'metadata_json',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'category' => AuditCategory::class,
            'metadata_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }
}
