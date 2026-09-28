<?php

namespace App\Domains\Notifications\Actions;

use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Data\TenantNotificationPolicy;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\QuietHours;
use App\Models\Team;

class SelectNotificationChannels
{
    public function __construct(
        private readonly TenantNotificationPoliciesResolver $policies,
    ) {}

    /**
     * @return array<int, NotificationChannel>
     */
    public function execute(Notification $notification, NotificationRecipient $recipient): array
    {
        /** @var Team|null $team */
        $team = $notification->team;

        if (! $team) {
            return [];
        }

        $policy = $this->policies->resolve($team);

        $channels = NotificationChannel::query()
            ->usableByTeam((int) $team->id)
            ->get();

        if ($channels->isEmpty()) {
            return [];
        }

        // Internal callers (e.g. automation Send* actions, Roadmap B7) pin the
        // exact channel the tenant configured for the action. The real gate
        // stays the same: an active NotificationChannel of that type must exist.
        $forced = $notification->payload_json['force_channels'] ?? null;

        if ($notification->priority->isCritical()) {
            if (is_array($forced) && $forced !== []) {
                return $this->onlyTypes($channels->all(), $forced);
            }

            $allowedTypes = collect($policy->criticalChannels)->map(fn (ChannelType $type) => $type->value);

            return $channels->filter(fn (NotificationChannel $channel) => $allowedTypes->contains($channel->channel_type->value))->values()->all();
        }

        $preference = $this->resolvePreference($notification, $recipient);
        $quiet = $this->insideQuietHours($team, $policy, $preference);

        if (is_array($forced) && $forced !== []) {
            return $this->withoutQuietChannels($this->onlyTypes($channels->all(), $forced), $quiet);
        }

        if ($preference !== null && $preference->muted && $notification->priority->suppressedByMute()) {
            return [];
        }

        $allowedTypes = $this->resolveAllowedTypes($preference, $policy);

        $filtered = $channels->filter(fn (NotificationChannel $channel) => in_array($channel->channel_type->value, $allowedTypes, true))
            ->values()
            ->all();

        $filtered = $this->withoutQuietChannels($filtered, $quiet);

        if ($recipient->channel_preference !== null) {
            $preferred = collect($filtered)
                ->filter(fn (NotificationChannel $channel) => $channel->channel_type->value === $recipient->channel_preference)
                ->values();

            if ($preferred->isNotEmpty()) {
                return $preferred->all();
            }
        }

        return $filtered;
    }

    /**
     * El horario de silencio del usuario manda sobre el del tenant.
     */
    private function insideQuietHours(Team $team, TenantNotificationPolicy $policy, ?NotificationPreference $preference): bool
    {
        $quietHours = is_array($preference?->quiet_hours_json) && $preference->quiet_hours_json !== []
            ? $preference->quiet_hours_json
            : $policy->quietHours;

        return QuietHours::isActive($quietHours, $team->timezone);
    }

    /**
     * @param  array<int, NotificationChannel>  $channels
     * @param  array<int, mixed>  $types
     * @return array<int, NotificationChannel>
     */
    private function onlyTypes(array $channels, array $types): array
    {
        return array_values(array_filter(
            $channels,
            fn (NotificationChannel $channel) => in_array($channel->channel_type->value, $types, true),
        ));
    }

    /**
     * @param  array<int, NotificationChannel>  $channels
     * @return array<int, NotificationChannel>
     */
    private function withoutQuietChannels(array $channels, bool $quiet): array
    {
        if (! $quiet) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            fn (NotificationChannel $channel) => ! QuietHours::silences($channel->channel_type),
        ));
    }

    private function resolvePreference(Notification $notification, NotificationRecipient $recipient): ?NotificationPreference
    {
        $userId = $recipient->recipient_reference_id !== null && is_numeric($recipient->recipient_reference_id)
            ? (int) $recipient->recipient_reference_id
            : null;

        if ($userId === null) {
            return null;
        }

        return NotificationPreference::query()
            ->where('team_id', $notification->team_id)
            ->where('user_id', $userId)
            ->where('notification_type', $notification->notification_type)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function resolveAllowedTypes(?NotificationPreference $preference, TenantNotificationPolicy $policy): array
    {
        if ($preference !== null && is_array($preference->allowed_channels_json) && count($preference->allowed_channels_json) > 0) {
            return array_values(array_filter(
                $preference->allowed_channels_json,
                fn ($value) => is_string($value),
            ));
        }

        return collect($policy->allowedChannels)
            ->map(fn (ChannelType $type) => $type->value)
            ->all();
    }
}
