<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\MessagingResourceType;
use Database\Factories\Domains\Notifications\MessagingChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un recurso Twilio (mensaje o llamada) originado para un tenant. Fuente única
 * del costo de proveedor por tenant; ver ReconcileMessagingChargesJob.
 *
 * @property int $team_id
 * @property MessagingResourceType $resource_type
 * @property MessagingChargeSource $source_type
 * @property ChannelType $channel_type
 * @property list<array{status: string, error_code: string|null, at: string, source: string}>|null $events_json
 */
class MessagingCharge extends Model
{
    /** @use HasFactory<MessagingChargeFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'messaging_charges';

    protected $fillable = [
        'team_id',
        'provider',
        'resource_type',
        'provider_sid',
        'source_type',
        'source_id',
        'channel_type',
        'status',
        'error_code',
        'segments',
        'duration_seconds',
        'price_micros',
        'price_unit',
        'price_estimated',
        'finalized_at',
        'metered_at',
        'last_checked_at',
        'next_check_at',
        'check_attempts',
        'events_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resource_type' => MessagingResourceType::class,
            'source_type' => MessagingChargeSource::class,
            'channel_type' => ChannelType::class,
            'source_id' => 'integer',
            'segments' => 'integer',
            'duration_seconds' => 'integer',
            'price_micros' => 'integer',
            'price_estimated' => 'boolean',
            'check_attempts' => 'integer',
            'events_json' => 'array',
            'finalized_at' => 'datetime',
            'metered_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MessagingChargeFactory
    {
        return MessagingChargeFactory::new();
    }
}
