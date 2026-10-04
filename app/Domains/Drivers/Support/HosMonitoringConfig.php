<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Notifications\Enums\ChannelType;

/**
 * A tenant's HOS monitoring configuration: the stored `hos.monitoring`
 * setting merged key by key over `config('hos.defaults')`.
 */
final readonly class HosMonitoringConfig
{
    public const string FEATURE_KEY = 'hos_monitoring';

    public const string SETTING_KEY = 'hos.monitoring';

    /** Canales con los que SAM le habla al chofer: no tiene correo ni cuenta en SAM. */
    public const array DRIVER_CHANNELS = ['samsara_driver_app', 'whatsapp', 'sms', 'voice'];

    /** Situaciones que el tenant puede apagar: la infracción siempre se vigila. */
    public const array CONFIGURABLE_SITUATIONS = ['break_due', 'drive_limit', 'shift_limit', 'cycle_limit', 'rest_complete'];

    /**
     * @param  array<int, string>  $tagIds
     * @param  array<int, int>  $includedAssetIds
     * @param  array<int, int>  $excludedAssetIds
     * @param  array<string, bool>  $situations
     * @param  array<int, int>  $leadMinutes
     * @param  array<int, int>  $cycleLeadHours
     * @param  array<int, int>  $restCompleteNudgeMinutes
     * @param  array<int, array<string, mixed>>  $ladder
     */
    public function __construct(
        public array $tagIds,
        public array $includedAssetIds,
        public array $excludedAssetIds,
        public array $situations,
        public array $leadMinutes,
        public array $cycleLeadHours,
        public array $restCompleteNudgeMinutes,
        public int $restCompleteExpireMinutes,
        public array $ladder,
    ) {}

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $defaults
     */
    public static function fromArray(array $stored, array $defaults): self
    {
        $value = fn (string $key): mixed => array_key_exists($key, $stored) && $stored[$key] !== null ? $stored[$key] : $defaults[$key];
        $ints = fn (mixed $list): array => array_values(array_map('intval', array_filter((array) $list, 'is_numeric')));

        return new self(
            tagIds: array_values(array_map('strval', array_filter((array) $value('tag_ids'), 'is_scalar'))),
            includedAssetIds: $ints($value('included_asset_ids')),
            excludedAssetIds: $ints($value('excluded_asset_ids')),
            situations: array_map('boolval', array_replace((array) $defaults['situations'], (array) ($stored['situations'] ?? []))),
            leadMinutes: $ints($value('lead_minutes')),
            cycleLeadHours: $ints($value('cycle_lead_hours')),
            restCompleteNudgeMinutes: $ints($value('rest_complete_nudge_minutes')),
            restCompleteExpireMinutes: (int) $value('rest_complete_expire_minutes'),
            ladder: self::ladderEntries($value('ladder')),
        );
    }

    public function enabled(HosSituation $situation): bool
    {
        // Una violación ya ocurrió: no es opcional observarla.
        if ($situation === HosSituation::Violation) {
            return true;
        }

        return $this->situations[$situation->value] ?? false;
    }

    /** Earliest warning before a limit: the largest lead (default 30 min). */
    public function leadSeconds(): int
    {
        return max([0, ...$this->leadMinutes]) * 60;
    }

    public function cycleLeadSeconds(): int
    {
        return max([0, ...$this->cycleLeadHours]) * 3600;
    }

    public function restCompleteExpireSeconds(): int
    {
        return $this->restCompleteExpireMinutes * 60;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function ladderEntries(mixed $ladder): array
    {
        /** @var array<int, array<string, mixed>> $entries */
        $entries = array_values(array_filter((array) $ladder, fn (mixed $entry): bool => is_array($entry)));

        return $entries;
    }

    /**
     * The ladder, cleaned: unknown channel types dropped, entries left without
     * a channel nor an escalation removed, sorted by `after_minutes`.
     *
     * @return list<array{after_minutes: int, channels: list<string>, escalate: bool}>
     */
    public function ladderSteps(): array
    {
        $steps = [];

        foreach ($this->ladder as $entry) {
            $channels = array_values(array_unique(array_filter(array_map(
                fn (mixed $channel): ?string => is_string($channel) ? ChannelType::tryFrom($channel)?->value : null,
                (array) ($entry['channels'] ?? []),
            ))));
            $escalate = ($entry['escalate'] ?? null) === 'incident';

            if ($channels === [] && ! $escalate) {
                continue;
            }

            $after = $entry['after_minutes'] ?? 0;

            $steps[] = [
                'after_minutes' => is_numeric($after) ? max(0, (int) $after) : 0,
                'channels' => $channels,
                'escalate' => $escalate,
            ];
        }

        usort($steps, fn (array $a, array $b): int => $a['after_minutes'] <=> $b['after_minutes']);

        return $steps;
    }

    /**
     * Channels of informational notices (warnings, cycle, rest complete):
     * the first ladder step that sends something — the free driver app by
     * default.
     *
     * @return list<string>
     */
    public function informationalChannels(): array
    {
        foreach ($this->ladderSteps() as $step) {
            if ($step['channels'] !== []) {
                return $step['channels'];
            }
        }

        return [ChannelType::SamsaraDriverApp->value];
    }

    /**
     * Warnings before a limit, largest first (the 0 is the limit itself).
     *
     * @return list<int>
     */
    public function leadThresholdsMinutes(): array
    {
        $minutes = array_values(array_unique(array_filter($this->leadMinutes, fn (int $value): bool => $value > 0)));
        rsort($minutes);

        return $minutes;
    }

    /**
     * @return list<int> largest first
     */
    public function cycleThresholdsHours(): array
    {
        $hours = array_values(array_unique(array_filter($this->cycleLeadHours, fn (int $value): bool => $value > 0)));
        rsort($hours);

        return $hours;
    }

    /**
     * @return list<int> smallest first
     */
    public function restNudgeMinutes(): array
    {
        $minutes = array_values(array_unique(array_filter($this->restCompleteNudgeMinutes, fn (int $value): bool => $value > 0)));
        sort($minutes);

        return $minutes;
    }
}
