<?php

namespace App\Domains\Normalization\Models;

use App\Domains\Integrations\Models\IntegrationProvider;
use Database\Factories\Domains\Normalization\EventMappingRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMappingRule extends Model
{
    /** @use HasFactory<EventMappingRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'provider_id',
        'external_event_type',
        'external_conditions_json',
        'mapped_event_type_id',
        'mapped_category_id',
        'mapped_severity_id',
        'priority',
        'is_active',
    ];

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    /**
     * @return BelongsTo<EventType, $this>
     */
    public function mappedEventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'mapped_event_type_id');
    }

    /**
     * @return BelongsTo<EventCategory, $this>
     */
    public function mappedCategory(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'mapped_category_id');
    }

    /**
     * @return BelongsTo<EventSeverity, $this>
     */
    public function mappedSeverity(): BelongsTo
    {
        return $this->belongsTo(EventSeverity::class, 'mapped_severity_id');
    }

    /**
     * @param  Builder<EventMappingRule>  $query
     * @return Builder<EventMappingRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_conditions_json' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): EventMappingRuleFactory
    {
        return EventMappingRuleFactory::new();
    }
}
