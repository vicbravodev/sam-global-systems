<?php

namespace App\Domains\Drivers\Models;

use App\Domains\Drivers\Enums\RiskLevel;
use Database\Factories\Domains\Drivers\DriverRiskProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverRiskProfile extends Model
{
    /** @use HasFactory<DriverRiskProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'risk_score',
        'risk_level',
        'incidents_count',
        'harsh_events_count',
        'fatigue_flags_count',
        'last_calculated_at',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'risk_score' => 'decimal:2',
            'risk_level' => RiskLevel::class,
            'incidents_count' => 'integer',
            'harsh_events_count' => 'integer',
            'fatigue_flags_count' => 'integer',
            'last_calculated_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): DriverRiskProfileFactory
    {
        return DriverRiskProfileFactory::new();
    }
}
