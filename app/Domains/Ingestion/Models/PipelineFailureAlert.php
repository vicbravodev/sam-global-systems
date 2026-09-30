<?php

namespace App\Domains\Ingestion\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\Ingestion\PipelineFailureAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una alerta enviada por el fallo definitivo de una etapa del pipeline (o por
 * agotar los rescates del barrido de atascados). `dedup_key` es único: es el
 * candado que garantiza una sola alerta por evento y etapa.
 */
class PipelineFailureAlert extends Model
{
    /** @use HasFactory<PipelineFailureAlertFactory> */
    use BelongsToTenant, HasFactory;

    public const string KIND_JOB_FAILED = 'job_failed';

    public const string KIND_REPROCESS_EXHAUSTED = 'reprocess_exhausted';

    /** Alerta del proveedor (posible emergencia) que ninguna regla reconoce. */
    public const string KIND_UNMAPPED_ALERT = 'unmapped_alert';

    protected $fillable = [
        'team_id',
        'dedup_key',
        'kind',
        'stage',
        'raw_event_id',
        'normalized_event_id',
        'event_type_code',
        'asset_id',
        'is_emergency',
        'error_json',
        'platform_recipients',
        'tenant_recipients',
        'notified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_emergency' => 'boolean',
            'error_json' => 'array',
            'platform_recipients' => 'integer',
            'tenant_recipients' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PipelineFailureAlertFactory
    {
        return PipelineFailureAlertFactory::new();
    }
}
