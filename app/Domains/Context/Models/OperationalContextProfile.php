<?php

namespace App\Domains\Context\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Context\Enums\RiskLevel;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Context\OperationalContextProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalContextProfile extends Model
{
    /** @use HasFactory<OperationalContextProfileFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'normalized_event_id',
        'team_id',
        'profile_code',
        'risk_level',
        'priority_score',
        'recurrence_score',
        'contextual_flags_json',
        'summary_json',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'risk_level' => RiskLevel::class,
            'priority_score' => 'decimal:2',
            'recurrence_score' => 'decimal:2',
            'contextual_flags_json' => 'array',
            'summary_json' => 'array',
        ];
    }

    protected static function newFactory(): OperationalContextProfileFactory
    {
        return OperationalContextProfileFactory::new();
    }
}
