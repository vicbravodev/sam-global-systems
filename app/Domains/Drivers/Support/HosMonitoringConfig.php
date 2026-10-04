<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Enums\HosSituation;

/**
 * A tenant's HOS monitoring configuration: the stored `hos.monitoring`
 * setting merged key by key over `config('hos.defaults')`.
 */
final readonly class HosMonitoringConfig
{
    public const string FEATURE_KEY = 'hos_monitoring';

    public const string SETTING_KEY = 'hos.monitoring';

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
            ladder: array_values((array) $value('ladder')),
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
}
