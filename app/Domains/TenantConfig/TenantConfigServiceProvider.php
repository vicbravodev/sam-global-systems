<?php

namespace App\Domains\TenantConfig;

use App\Contracts\TenantConfig\TenantAIProfileResolver;
use App\Contracts\TenantConfig\TenantAnalyticsConfig;
use App\Contracts\TenantConfig\TenantAutomationPoliciesResolver;
use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Contracts\TenantConfig\TenantNotificationPolicyResolver;
use App\Contracts\TenantConfig\TenantRuleOverrideApplier;
use App\Contracts\TenantConfig\TenantScheduleResolver;
use App\Domains\TenantConfig\Actions\ApplyTenantRuleOverrides;
use App\Domains\TenantConfig\Actions\ResolveTenantAIProfile;
use App\Domains\TenantConfig\Actions\ResolveTenantAnalyticsConfig;
use App\Domains\TenantConfig\Actions\ResolveTenantAutomationPolicies;
use App\Domains\TenantConfig\Actions\ResolveTenantDecisionRules;
use App\Domains\TenantConfig\Actions\ResolveTenantNotificationPolicies;
use App\Domains\TenantConfig\Actions\ResolveTenantNotificationPolicy;
use App\Domains\TenantConfig\Actions\ResolveTenantSchedule;
use App\Domains\TenantConfig\Actions\ResolveTenantSetting;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Domains\TenantConfig\Policies\TenantAIProfilePolicy;
use App\Domains\TenantConfig\Policies\TenantConfigVersionPolicy;
use App\Domains\TenantConfig\Policies\TenantEscalationConfigPolicy;
use App\Domains\TenantConfig\Policies\TenantNotificationPolicyPolicy;
use App\Domains\TenantConfig\Policies\TenantRuleOverridePolicy;
use App\Domains\TenantConfig\Policies\TenantScheduleProfilePolicy;
use App\Domains\TenantConfig\Policies\TenantSettingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TenantConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(TenantConfigResolver::class, ResolveTenantSetting::class);
        $this->app->singletonIf(TenantAIProfileResolver::class, ResolveTenantAIProfile::class);
        $this->app->singletonIf(TenantNotificationPolicyResolver::class, ResolveTenantNotificationPolicy::class);
        $this->app->singletonIf(TenantNotificationPoliciesResolver::class, ResolveTenantNotificationPolicies::class);
        $this->app->singletonIf(TenantScheduleResolver::class, ResolveTenantSchedule::class);
        $this->app->singletonIf(TenantRuleOverrideApplier::class, ApplyTenantRuleOverrides::class);
        $this->app->singletonIf(TenantDecisionRulesResolver::class, ResolveTenantDecisionRules::class);
        $this->app->singletonIf(TenantAutomationPoliciesResolver::class, ResolveTenantAutomationPolicies::class);
        $this->app->singletonIf(TenantAnalyticsConfig::class, ResolveTenantAnalyticsConfig::class);
    }

    public function boot(): void
    {
        Gate::policy(TenantSetting::class, TenantSettingPolicy::class);
        Gate::policy(TenantRuleOverride::class, TenantRuleOverridePolicy::class);
        Gate::policy(TenantNotificationPolicy::class, TenantNotificationPolicyPolicy::class);
        Gate::policy(TenantAIProfile::class, TenantAIProfilePolicy::class);
        Gate::policy(TenantEscalationConfig::class, TenantEscalationConfigPolicy::class);
        Gate::policy(TenantScheduleProfile::class, TenantScheduleProfilePolicy::class);
        Gate::policy(TenantConfigVersion::class, TenantConfigVersionPolicy::class);
    }
}
