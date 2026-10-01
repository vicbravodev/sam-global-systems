<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\RecipientType;
use Database\Factories\Domains\Notifications\NotificationRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationRecipient extends Model
{
    /** @use HasFactory<NotificationRecipientFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'notification_recipients';

    protected $fillable = [
        'notification_id',
        'team_id',
        'recipient_type',
        'recipient_reference_id',
        'name',
        'address',
        'email',
        'phone',
        'channel_preference',
        'role',
        'metadata_json',
    ];

    /**
     * The destination to use for a given channel: telephony channels need a
     * phone, mail needs an email (falling back to the legacy address), and
     * everything else keeps using the legacy address.
     */
    public function addressForChannel(ChannelType $channelType): ?string
    {
        return match ($channelType) {
            ChannelType::Sms, ChannelType::Voice, ChannelType::Whatsapp => self::presentOrNull($this->phone),
            ChannelType::Email => self::presentOrNull($this->email) ?? self::presentOrNull($this->address),
            default => self::presentOrNull($this->address),
        };
    }

    /**
     * Lectura falsy de siempre: null, '' y '0' no son una dirección.
     */
    private static function presentOrNull(?string $value): ?string
    {
        return in_array($value, [null, '', '0'], true) ? null : $value;
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'recipient_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_type' => RecipientType::class,
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): NotificationRecipientFactory
    {
        return NotificationRecipientFactory::new();
    }
}
