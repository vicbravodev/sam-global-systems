<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;

/**
 * Canales que SAM realmente ofrece: los tipos de los canales de plataforma
 * activos (`notification_channels`, sin team_id — V2-B1). Slack o
 * Webhook existen en el enum pero no se ofrecen mientras no haya un canal de
 * plataforma que los entregue, así que ninguna pantalla los debe proponer.
 */
class ProvidedChannels
{
    /**
     * @return array<int, ChannelType>
     */
    public function types(): array
    {
        $active = NotificationChannel::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('channel_type')
            ->map(fn ($type): string => $type instanceof ChannelType ? $type->value : (string) $type)
            ->all();

        return array_values(array_filter(
            ChannelType::cases(),
            fn (ChannelType $type): bool => in_array($type->value, $active, true),
        ));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        return array_map(
            fn (ChannelType $type): array => ['value' => $type->value, 'label' => $type->label()],
            $this->types(),
        );
    }
}
