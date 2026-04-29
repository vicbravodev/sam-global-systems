<?php

namespace App\Domains\TenantConfig\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use Database\Factories\Domains\TenantConfig\TenantAIProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantAIProfile extends Model
{
    /** @use HasFactory<TenantAIProfileFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'tenant_ai_profiles';

    protected $fillable = [
        'team_id',
        'profile_code',
        'name',
        'description',
        'prompt_overrides_json',
        'risk_tolerance',
        'false_positive_tolerance',
        'automation_level',
        'media_strategy',
        'human_review_policy_json',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_overrides_json' => 'array',
            'risk_tolerance' => RiskTolerance::class,
            'false_positive_tolerance' => FalsePositiveTolerance::class,
            'automation_level' => AutomationLevel::class,
            'media_strategy' => MediaStrategy::class,
            'human_review_policy_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TenantAIProfileFactory
    {
        return TenantAIProfileFactory::new();
    }
}
