<?php

namespace App\Domains\Analytics\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Analytics\Enums\DimensionType;
use App\Domains\Analytics\Enums\PeriodType;
use Database\Factories\Domains\Analytics\KpiRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiRecord extends Model
{
    /** @use HasFactory<KpiRecordFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'kpi_code',
        'period_type',
        'period_start',
        'period_end',
        'dimension_type',
        'dimension_reference',
        'value',
        'unit',
        'metadata_json',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_type' => PeriodType::class,
            'dimension_type' => DimensionType::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'value' => 'float',
            'metadata_json' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): KpiRecordFactory
    {
        return KpiRecordFactory::new();
    }
}
