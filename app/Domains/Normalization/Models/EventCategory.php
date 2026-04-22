<?php

namespace App\Domains\Normalization\Models;

use Database\Factories\Domains\Normalization\EventCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventCategory extends Model
{
    /** @use HasFactory<EventCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    /**
     * @return HasMany<EventType, $this>
     */
    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class, 'category_id');
    }

    protected static function newFactory(): EventCategoryFactory
    {
        return EventCategoryFactory::new();
    }
}
