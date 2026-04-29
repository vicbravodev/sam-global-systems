<?php

namespace App\Domains\Analytics\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Analytics\Enums\SnapshotType;
use Database\Factories\Domains\Analytics\AnalyticsSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnalyticsSnapshot extends Model
{
    /** @use HasFactory<AnalyticsSnapshotFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'snapshot_type',
        'entity_type',
        'entity_id',
        'period_start',
        'period_end',
        'snapshot_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_type' => SnapshotType::class,
            'entity_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'snapshot_json' => 'array',
        ];
    }

    protected static function newFactory(): AnalyticsSnapshotFactory
    {
        return AnalyticsSnapshotFactory::new();
    }
}
