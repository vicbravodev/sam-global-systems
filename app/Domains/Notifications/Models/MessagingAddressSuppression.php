<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Enums\ChannelType;
use Database\Factories\Domains\Notifications\MessagingAddressSuppressionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Dirección suprimida para un canal (ver la migración): fila de plataforma,
 * sin tenant — el remitente Twilio es compartido. La administra
 * MessagingSuppressions.
 */
class MessagingAddressSuppression extends Model
{
    /** @use HasFactory<MessagingAddressSuppressionFactory> */
    use HasFactory;

    protected $fillable = [
        'channel_type',
        'address',
        'reason',
        'provider_error_code',
        'source',
        'suppressed_at',
    ];

    protected static function newFactory(): MessagingAddressSuppressionFactory
    {
        return MessagingAddressSuppressionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'suppressed_at' => 'datetime',
        ];
    }
}
