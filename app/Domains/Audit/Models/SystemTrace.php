<?php

namespace App\Domains\Audit\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Audit\Enums\TraceStatus;
use App\Domains\Audit\Support\AppendOnly;
use Database\Factories\Domains\Audit\SystemTraceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemTrace extends Model
{
    /** @use HasFactory<SystemTraceFactory> */
    use AppendOnly, BelongsToTenant, HasFactory;

    protected $table = 'system_traces';

    protected $fillable = [
        'trace_id',
        'span_id',
        'parent_span_id',
        'team_id',
        'module_name',
        'operation_name',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'input_reference_json',
        'output_reference_json',
        'error_message',
        'metadata_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TraceStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'input_reference_json' => 'array',
            'output_reference_json' => 'array',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): SystemTraceFactory
    {
        return SystemTraceFactory::new();
    }
}
