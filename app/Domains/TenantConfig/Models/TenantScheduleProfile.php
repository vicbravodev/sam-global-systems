<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\Domains\TenantConfig\TenantScheduleProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantScheduleProfile extends Model
{
    /** @use HasFactory<TenantScheduleProfileFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_schedule_profiles';

    protected $fillable = [
        'team_id',
        'profile_code',
        'timezone',
        'operating_hours_json',
        'holidays_json',
        'shift_rules_json',
        'after_hours_behavior_json',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operating_hours_json' => 'array',
            'holidays_json' => 'array',
            'shift_rules_json' => 'array',
            'after_hours_behavior_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TenantScheduleProfileFactory
    {
        return TenantScheduleProfileFactory::new();
    }
}
