<?php

namespace App\Domains\Notifications\Actions;

use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Data\TenantNotificationPolicy;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\QuietHours;
use App\Models\Team;
use App\Support\LoggableCode;

class SelectNotificationChannels
{
    /**
     * HOS situations (payload `hos.situation`, the HosSituation values) whose
     * driver notices skip quiet hours: road safety while he drives. Rest
     * complete and the cycle warning are informational and respect them.
     */
    private const array HOS_QUIET_HOURS_BYPASS = ['break_due', 'drive_limit', 'shift_limit', 'violation'];

    public function __construct(
        private readonly TenantNotificationPoliciesResolver $policies,
    ) {}

    /**
     * @return array<int, NotificationChannel>
     */
    public function execute(Notification $notification, NotificationRecipient $recipient): array
    {
        return $this->explain($notification, $recipient)['channels'];
    }

    /**
     * La misma selección, con la rama que la decidió y sus términos. Las ramas
     * críticas no leen preferencia ni silencio: esos campos quedan null.
     *
     * @return array{channels: list<NotificationChannel>, branch: string, calc: array<string, mixed>}
     */
    public function explain(Notification $notification, NotificationRecipient $recipient): array
    {
        $calc = [
            'priority' => $notification->priority->value,
            'usable_channel_types' => null,
            'forced_types' => null,
            'critical_policy_types' => null,
            'quiet_hours_active' => null,
            'quiet_hours_source' => null,
            'silenced_types' => null,
            'muted' => null,
            'allowed_types' => null,
            'allowed_types_source' => null,
            'recipient_channel_preference' => null,
            'preference_matched' => null,
        ];

        /** @var Team|null $team */
        $team = $notification->team;

        if ($team === null) {
            return $this->selection([], 'no_team', $calc);
        }

        $policy = $this->policies->resolve($team);

        $channels = NotificationChannel::query()
            ->usableByTeam($team->id)
            ->get();

        $calc['usable_channel_types'] = $this->typesOf($channels->all());

        if ($channels->isEmpty()) {
            return $this->selection([], 'no_usable_channels', $calc);
        }

        // Internal callers (e.g. automation Send* actions, Roadmap B7) pin the
        // exact channel the tenant configured for the action. The real gate
        // stays the same: an active NotificationChannel of that type must exist.
        $forced = $notification->payload_json['force_channels'] ?? null;

        if (is_array($forced) && $forced !== []) {
            $calc['forced_types'] = array_values(array_filter(array_map(
                static fn (mixed $type): ?string => is_string($type) ? ChannelType::tryFrom($type)?->value : null,
                $forced,
            )));
        }

        if ($notification->priority->isCritical()) {
            $allowedTypes = collect($policy->criticalChannels)->map(fn (ChannelType $type) => $type->value);
            $calc['critical_policy_types'] = $allowedTypes->values()->all();

            if (is_array($forced) && $forced !== []) {
                return $this->selection($this->onlyTypes($channels->all(), $forced), 'critical_forced', $calc);
            }

            return $this->selection(
                $channels->filter(fn (NotificationChannel $channel) => $allowedTypes->contains($channel->channel_type->value))->values()->all(),
                'critical_policy',
                $calc,
            );
        }

        $preference = $this->resolvePreference($notification, $recipient);
        // Avisos HOS de seguridad al chofer (spec 2026-10-04 §3.10): va en ruta,
        // así que no hay horario silencioso. Fin de pausa y ciclo sí lo respetan.
        $quiet = $this->bypassesQuietHours($notification)
            ? ['active' => false, 'source' => 'bypassed']
            : $this->insideQuietHours($team, $policy, $preference);

        $calc['quiet_hours_active'] = $quiet['active'];
        $calc['quiet_hours_source'] = $quiet['source'];
        $calc['muted'] = $preference !== null ? $preference->muted : null;

        if (is_array($forced) && $forced !== []) {
            $candidates = $this->onlyTypes($channels->all(), $forced);
            $kept = $this->withoutQuietChannels($candidates, $quiet['active']);
            $calc['silenced_types'] = $this->silencedTypes($candidates, $kept);

            return $this->selection($kept, 'forced', $calc);
        }

        if ($preference !== null && $preference->muted && $notification->priority->suppressedByMute()) {
            return $this->selection([], 'muted', $calc);
        }

        $allowed = $this->resolveAllowedTypes($preference, $policy);
        $allowedTypes = $allowed['types'];
        // Sólo tipos reales: allowed_channels_json puede traer valores viejos o sembrados.
        $calc['allowed_types'] = array_values(array_filter(array_map(
            static fn (string $type): ?string => ChannelType::tryFrom($type)?->value,
            $allowedTypes,
        )));
        $calc['allowed_types_source'] = $allowed['source'];

        $candidates = $channels->filter(fn (NotificationChannel $channel) => in_array($channel->channel_type->value, $allowedTypes, true))
            ->values()
            ->all();

        $filtered = $this->withoutQuietChannels($candidates, $quiet['active']);
        $calc['silenced_types'] = $this->silencedTypes($candidates, $filtered);

        if ($recipient->channel_preference !== null) {
            $calc['recipient_channel_preference'] = LoggableCode::guard($recipient->channel_preference);

            $preferred = collect($filtered)
                ->filter(fn (NotificationChannel $channel) => $channel->channel_type->value === $recipient->channel_preference)
                ->values();

            $calc['preference_matched'] = $preferred->isNotEmpty();

            if ($preferred->isNotEmpty()) {
                return $this->selection($preferred->all(), 'recipient_preference', $calc);
            }
        }

        return $this->selection($filtered, 'allowed_types', $calc);
    }

    /**
     * @param  array<int, NotificationChannel>  $channels
     * @param  array<string, mixed>  $calc
     * @return array{channels: list<NotificationChannel>, branch: string, calc: array<string, mixed>}
     */
    private function selection(array $channels, string $branch, array $calc): array
    {
        $channels = array_values($channels);

        $calc['selected_types'] = $this->typesOf($channels);
        $calc['selected_channel_ids'] = array_map(fn (NotificationChannel $channel) => $channel->id, $channels);

        return ['channels' => $channels, 'branch' => $branch, 'calc' => $calc];
    }

    /**
     * @param  array<int, NotificationChannel>  $channels
     * @return list<string>
     */
    private function typesOf(array $channels): array
    {
        return array_values(array_unique(array_map(fn (NotificationChannel $channel) => $channel->channel_type->value, $channels)));
    }

    /**
     * @param  array<int, NotificationChannel>  $before
     * @param  array<int, NotificationChannel>  $after
     * @return list<string>
     */
    private function silencedTypes(array $before, array $after): array
    {
        return array_values(array_diff($this->typesOf($before), $this->typesOf($after)));
    }

    private function bypassesQuietHours(Notification $notification): bool
    {
        if ($notification->source_type !== NotificationSourceType::HosEpisode) {
            return false;
        }

        $hos = $notification->payload_json['hos'] ?? null;
        $situation = is_array($hos) ? ($hos['situation'] ?? null) : null;

        return is_string($situation) && in_array($situation, self::HOS_QUIET_HOURS_BYPASS, true);
    }

    /**
     * El horario de silencio del usuario manda sobre el del tenant.
     *
     * @return array{active: bool, source: 'user_preference'|'tenant_policy'|'none'}
     */
    private function insideQuietHours(Team $team, TenantNotificationPolicy $policy, ?NotificationPreference $preference): array
    {
        $fromPreference = is_array($preference?->quiet_hours_json) && $preference->quiet_hours_json !== [];

        $quietHours = $fromPreference
            ? $preference->quiet_hours_json
            : $policy->quietHours;

        $source = match (true) {
            $fromPreference => 'user_preference',
            is_array($quietHours) && $quietHours !== [] => 'tenant_policy',
            default => 'none',
        };

        return ['active' => QuietHours::isActive($quietHours, $team->timezone), 'source' => $source];
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
        $userId = $recipient->recipient_type === RecipientType::User
            && $recipient->recipient_reference_id !== null
            && is_numeric($recipient->recipient_reference_id)
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
     * @return array{types: array<int, string>, source: 'user_preference'|'tenant_policy'}
     */
    private function resolveAllowedTypes(?NotificationPreference $preference, TenantNotificationPolicy $policy): array
    {
        if ($preference !== null && count($preference->allowed_channels_json) > 0) {
            return [
                'types' => array_values(array_filter(
                    $preference->allowed_channels_json,
                    fn ($value) => is_string($value),
                )),
                'source' => 'user_preference',
            ];
        }

        return [
            'types' => collect($policy->allowedChannels)
                ->map(fn (ChannelType $type) => $type->value)
                ->all(),
            'source' => 'tenant_policy',
        ];
    }
}
