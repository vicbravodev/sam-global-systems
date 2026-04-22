<?php

namespace App\Domains\Drivers\Models;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use Database\Factories\Domains\Drivers\DriverAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverAssignment extends Model
{
    /** @use HasFactory<DriverAssignmentFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'driver_id',
        'asset_id',
        'assignment_type',
        'started_at',
        'ended_at',
        'source',
        'source_reference_id',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @param  Builder<DriverAssignment>  $query
     * @return Builder<DriverAssignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @param  Builder<DriverAssignment>  $query
     * @return Builder<DriverAssignment>
     */
    public function scopeActiveAt(Builder $query, \DateTimeInterface $timestamp): Builder
    {
        return $query->where('started_at', '<=', $timestamp)
            ->where(function (Builder $q) use ($timestamp) {
                $q->whereNull('ended_at')
                    ->orWhere('ended_at', '>', $timestamp);
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignment_type' => AssignmentType::class,
            'source' => AssignmentSource::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): DriverAssignmentFactory
    {
        return DriverAssignmentFactory::new();
    }
}
