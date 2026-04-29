<?php

namespace App\Domains\Analytics\Models;

use App\Domains\Analytics\Enums\MetricAggregationType;
use Database\Factories\Domains\Analytics\MetricDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetricDefinition extends Model
{
    /** @use HasFactory<MetricDefinitionFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'formula_description',
        'unit',
        'aggregation_type',
        'source_modules_json',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aggregation_type' => MetricAggregationType::class,
            'source_modules_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): MetricDefinitionFactory
    {
        return MetricDefinitionFactory::new();
    }
}
