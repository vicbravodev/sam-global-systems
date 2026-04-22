<?php

namespace App\Domains\Drivers\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Enums\DriverStatus;
use Database\Factories\Domains\Drivers\DriverFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    /** @use HasFactory<DriverFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id',
        'external_primary_id',
        'first_name',
        'last_name',
        'full_name',
        'employee_code',
        'status',
        'metadata_json',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * @return HasMany<DriverAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(DriverAssignment::class);
    }

    /**
     * @return HasOne<DriverAssignment, $this>
     */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(DriverAssignment::class)
            ->where('assignment_type', AssignmentType::PrimaryDriver)
            ->whereNull('ended_at')
            ->latestOfMany('started_at');
    }

    /**
     * @return HasMany<DriverStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(DriverStatusLog::class);
    }

    /**
     * @return HasMany<DriverContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(DriverContact::class);
    }

    /**
     * @return HasMany<DriverDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }

    /**
     * @return HasMany<DriverExternalReference, $this>
     */
    public function externalReferences(): HasMany
    {
        return $this->hasMany(DriverExternalReference::class);
    }

    /**
     * @return HasOne<DriverRiskProfile, $this>
     */
    public function riskProfile(): HasOne
    {
        return $this->hasOne(DriverRiskProfile::class);
    }

    /**
     * @param  Builder<Driver>  $query
     * @return Builder<Driver>
     */
    public function scopeWithStatus(Builder $query, DriverStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<Driver>  $query
     * @return Builder<Driver>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DriverStatus::Active);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'metadata_json' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DriverFactory
    {
        return DriverFactory::new();
    }
}
