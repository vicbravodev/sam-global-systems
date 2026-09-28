<?php

namespace Database\Seeders\Showcase\Support;

/**
 * Qué "pasó de verdad" con un evento simulado: cómo lo clasifica la IA, qué
 * decide el motor y si termina en incidente. Se deriva de forma determinista
 * del id del evento ({@see ShowcaseEventCatalog::scenarioFor()}), así que
 * todos los pasos (IA, decisiones, incidentes, notificaciones) coinciden
 * aunque corran en corridas distintas.
 */
final class EventScenario
{
    public function __construct(
        public readonly string $typeCode,
        public readonly bool $evaluate,
        public readonly string $classification,
        public readonly float $confidence,
        public readonly float $riskScore,
        public readonly string $aiPriority,
        public readonly string $recommendedAction,
        public readonly string $decisionCode,
        public readonly string $decisionPriority,
        public readonly bool $requiresHumanReview,
        public readonly ?string $incidentType,
        public readonly ?string $incidentPriority,
        public readonly bool $withMedia,
        public readonly bool $humanOverride,
    ) {}

    public function isReal(): bool
    {
        return $this->classification === 'real_event';
    }

    public function opensIncident(): bool
    {
        return $this->incidentType !== null;
    }
}
