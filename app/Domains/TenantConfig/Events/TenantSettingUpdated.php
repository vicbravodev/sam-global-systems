<?php

namespace App\Domains\TenantConfig\Events;

use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantSettingUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $teamId,
        public string $settingKey,
        public SettingGroup $settingGroup,
        public mixed $previousValue,
        public mixed $newValue,
        public SettingUpdatedByType $updatedByType,
        public ?int $updatedById = null,
    ) {}
}
