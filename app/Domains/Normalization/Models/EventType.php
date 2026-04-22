<?php

namespace App\Domains\Normalization\Models;

use Database\Factories\Domains\Normalization\EventTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventType extends Model
{
    /** @use HasFactory<EventTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'category_id',
        'default_severity_id',
        'is_active',
    ];

    /**
     * @return BelongsTo<EventCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<EventSeverity, $this>
     */
    public function defaultSeverity(): BelongsTo
    {
        return $this->belongsTo(EventSeverity::class, 'default_severity_id');
    }

    /**
     * @param  Builder<EventType>  $query
     * @return Builder<EventType>
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
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): EventTypeFactory
    {
        return EventTypeFactory::new();
    }
}
