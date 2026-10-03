<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantAIProfileResolver;
use App\Contracts\TenantConfig\TenantDecisionRulesResolver;
use App\Domains\Decisions\Data\TenantDecisionPolicy;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/**
 * Traduce el «Nivel de autonomía» del perfil de IA del tenant al umbral de
 * confianza bajo el cual el motor de decisiones pide revisión humana
 * (`ResolveDecisionOutcome`: `confidence < threshold`). Los umbrales viven en
 * `config('ai.automation_levels.human_review_threshold')`. La caché se
 * invalida en `UpdateTenantAIProfile`.
 */
class ResolveTenantDecisionRules implements TenantDecisionRulesResolver
{
    public function __construct(
        private readonly TenantAIProfileResolver $aiProfileResolver,
    ) {}

    public function resolve(int $teamId): TenantDecisionPolicy
    {
        return Cache::remember(
            CacheKeys::decisionRules($teamId),
            CacheKeys::TTL_SECONDS,
            function () use ($teamId): TenantDecisionPolicy {
                $level = $this->aiProfileResolver->resolve($teamId)->automationLevel;

                return new TenantDecisionPolicy(
                    humanReviewConfidenceThreshold: self::humanReviewThreshold($level),
                    automationLevel: $level->value,
                );
            },
        );
    }

    /**
     * Sin valor por defecto a propósito: un nivel sin umbral configurado es
     * un error de despliegue y debe fallar ruidosamente, no caer en silencio
     * a otro nivel.
     */
    public static function humanReviewThreshold(AutomationLevel $level): float
    {
        return config()->float("ai.automation_levels.human_review_threshold.{$level->value}");
    }
}
