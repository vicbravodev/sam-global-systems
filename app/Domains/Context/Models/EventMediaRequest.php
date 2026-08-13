<?php

namespace App\Domains\Context\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Enums\MediaRequestType;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Models\NormalizedEvent;
use Database\Factories\Domains\Context\EventMediaRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventMediaRequest extends Model
{
    /** @use HasFactory<EventMediaRequestFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'normalized_event_id',
        'provider_id',
        'request_type',
        'sweep_only',
        'requested_at',
        'status',
        'response_metadata_json',
        'expires_at',
        'completed_at',
    ];

    /**
     * @return BelongsTo<NormalizedEvent, $this>
     */
    public function normalizedEvent(): BelongsTo
    {
        return $this->belongsTo(NormalizedEvent::class, 'normalized_event_id');
    }

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_type' => MediaRequestType::class,
            'sweep_only' => 'boolean',
            'status' => MediaRequestStatus::class,
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'response_metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): EventMediaRequestFactory
    {
        return EventMediaRequestFactory::new();
    }
}
