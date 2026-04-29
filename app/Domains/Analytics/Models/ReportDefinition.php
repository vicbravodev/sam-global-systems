<?php

namespace App\Domains\Analytics\Models;

use App\Domains\Analytics\Enums\ReportType;
use App\Models\Team;
use Database\Factories\Domains\Analytics\ReportDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportDefinition extends Model
{
    /** @use HasFactory<ReportDefinitionFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'code',
        'name',
        'description',
        'report_type',
        'data_sources_json',
        'filters_schema_json',
        'metrics_json',
        'visualization_config_json',
        'schedule_config_json',
        'is_active',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * @return HasMany<ReportExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(ReportExecution::class, 'report_definition_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'data_sources_json' => 'array',
            'filters_schema_json' => 'array',
            'metrics_json' => 'array',
            'visualization_config_json' => 'array',
            'schedule_config_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): ReportDefinitionFactory
    {
        return ReportDefinitionFactory::new();
    }
}
