<?php

namespace App\Domains\Analytics\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Tenancy\Models\FileObject;
use Database\Factories\Domains\Analytics\ReportExecutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExecution extends Model
{
    /** @use HasFactory<ReportExecutionFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'report_definition_id',
        'team_id',
        'requested_by_type',
        'requested_by_id',
        'filters_json',
        'status',
        'output_format',
        'file_path',
        'output_file_object_id',
        'result_snapshot_json',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return BelongsTo<ReportDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    /**
     * @return BelongsTo<FileObject, $this>
     */
    public function outputFileObject(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'output_file_object_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_by_type' => ReportRequestedByType::class,
            'requested_by_id' => 'integer',
            'filters_json' => 'array',
            'status' => ReportExecutionStatus::class,
            'output_format' => ReportOutputFormat::class,
            'result_snapshot_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ReportExecutionFactory
    {
        return ReportExecutionFactory::new();
    }
}
