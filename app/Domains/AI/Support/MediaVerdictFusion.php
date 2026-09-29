<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\Normalization\Models\NormalizedEvent;

/**
 * Fusión determinista del veredicto visual en la evaluación del evento.
 *
 * Recibe los veredictos por media (los mismos que viajan en
 * `AIInputContext::$mediaAssessments`) y produce un ajuste transparente y
 * acotado: un paso de razonamiento en español, una oración para la
 * explicación, deltas de confianza/riesgo y contadores para key_factors.
 * Solo actúa cuando hay veredictos decisivos (confirma/contradice); con
 * media inconclusa o sin media no cambia nada.
 *
 * Reglas de seguridad:
 * - Una sola media que confirma el evento (o que ve una amenaza visible)
 *   domina sobre cualquier número de medias que lo contradicen: una cámara
 *   que no ve nada no borra a otra que sí vio algo.
 * - El delta de confianza respeta la dirección de la clasificación: si la IA
 *   dijo falso positivo/ruido/duplicado, las imágenes que contradicen el
 *   evento REFUERZAN esa conclusión y las que lo confirman la DEBILITAN.
 * - En eventos críticos (severidad `critical` o categoría `emergency`) que
 *   las imágenes no confirmen nunca baja el riesgo: solo se anota.
 */
class MediaVerdictFusion
{
    private const CONFIDENCE_DELTA_CONTRADICTS = 0.15;

    private const RISK_DELTA_CONTRADICTS = -0.15;

    private const CONFIDENCE_DELTA_CONFIRMS = 0.10;

    private const RISK_DELTA_CONFIRMS = 0.10;

    /** @var list<string> */
    private const CRITICAL_SEVERITY_CODES = ['critical'];

    /** @var list<string> */
    private const CRITICAL_CATEGORY_CODES = ['emergency'];

    /**
     * ¿El evento es de los que nunca pueden perder riesgo por falta de
     * confirmación visual?
     */
    public static function isCriticalEvent(NormalizedEvent $event): bool
    {
        $event->loadMissing(['eventSeverity', 'eventCategory']);

        return in_array($event->eventSeverity?->code, self::CRITICAL_SEVERITY_CODES, true)
            || in_array($event->eventCategory?->code, self::CRITICAL_CATEGORY_CODES, true);
    }

    /**
     * @param  list<array<string, mixed>>  $mediaAssessments
     * @return array{step: string, sentence: string, confidenceDelta: float, riskDelta: float, keyFactors: array<string, int>}|null
     */
    public function fuse(array $mediaAssessments, EventClassification $classification, bool $isCriticalEvent = false): ?array
    {
        return $this->explain($mediaAssessments, $classification, $isCriticalEvent)['fusion'];
    }

    /**
     * Igual que `fuse()` pero con la rama tomada y los contadores, para la
     * narrativa: `no_media`, `no_verdict`, `confirms`, `critical_no_reduce`
     * o `contradicts`. Puro (solo arrays).
     *
     * @param  list<array<string, mixed>>  $mediaAssessments
     * @return array{branch: string, fusion: array{step: string, sentence: string, confidenceDelta: float, riskDelta: float, keyFactors: array<string, int>}|null, assessed: int, confirms: int, contradicts: int, visible_threats: int, dismissive: bool}
     */
    public function explain(array $mediaAssessments, EventClassification $classification, bool $isCriticalEvent = false): array
    {
        $assessed = count($mediaAssessments);

        $explain = fn (string $branch, ?array $fusion, int $confirms = 0, int $contradicts = 0, int $visibleThreats = 0, bool $dismissive = false): array => [
            'branch' => $branch,
            'fusion' => $fusion,
            'assessed' => $assessed,
            'confirms' => $confirms,
            'contradicts' => $contradicts,
            'visible_threats' => $visibleThreats,
            'dismissive' => $dismissive,
        ];

        if ($assessed === 0) {
            return $explain('no_media', null);
        }

        $confirms = 0;
        $contradicts = 0;
        $visibleThreats = 0;

        foreach ($mediaAssessments as $assessment) {
            $result = (string) ($assessment['result'] ?? '');
            $visibleThreat = ($assessment['extracted_signals']['visible_threat'] ?? null) === true;

            if ($visibleThreat) {
                $visibleThreats++;
            }

            if ($result === 'confirms_event' || $visibleThreat) {
                $confirms++;
            } elseif ($result === 'contradicts_event') {
                $contradicts++;
            }
        }

        if ($contradicts === 0 && $confirms === 0) {
            return $explain('no_verdict', null, $confirms, $contradicts, $visibleThreats);
        }

        $keyFactors = [
            'media_assessed_count' => $assessed,
            'media_confirms_count' => $confirms,
            'media_contradicts_count' => $contradicts,
            'media_visible_threat_count' => $visibleThreats,
        ];

        // La IA concluyó que el evento no es real: la dirección de la
        // confianza se invierte respecto a la del evento.
        $dismissive = in_array($classification, [
            EventClassification::FalsePositive,
            EventClassification::Noise,
            EventClassification::Duplicate,
        ], true);

        $medias = $assessed === 1 ? 'media evaluada' : 'medias evaluadas';

        if ($confirms > 0) {
            $sentence = sprintf(
                'Análisis visual: %d de %d %s %s el evento%s.',
                $confirms,
                $assessed,
                $medias,
                $confirms === 1 ? 'confirma' : 'confirman',
                $visibleThreats > 0 ? ' (amenaza visible)' : '',
            );

            if ($dismissive) {
                $sentence .= ' Contradice la clasificación de la IA como '.mb_strtolower($classification->label()).'.';
            }

            return $explain('confirms', [
                'step' => $sentence,
                'sentence' => $sentence,
                'confidenceDelta' => $dismissive ? -self::CONFIDENCE_DELTA_CONFIRMS : self::CONFIDENCE_DELTA_CONFIRMS,
                'riskDelta' => self::RISK_DELTA_CONFIRMS,
                'keyFactors' => $keyFactors,
            ], $confirms, $contradicts, $visibleThreats, $dismissive);
        }

        if ($isCriticalEvent) {
            $sentence = sprintf(
                'Análisis visual: sin confirmación visual (%d de %d %s no muestran el evento); '
                .'al ser un evento crítico no se reduce el riesgo.',
                $contradicts,
                $assessed,
                $medias,
            );

            return $explain('critical_no_reduce', [
                'step' => $sentence,
                'sentence' => $sentence,
                'confidenceDelta' => 0.0,
                'riskDelta' => 0.0,
                'keyFactors' => $keyFactors,
            ], $confirms, $contradicts, $visibleThreats, $dismissive);
        }

        $sentence = sprintf(
            'Análisis visual: %d de %d %s %s el evento.',
            $contradicts,
            $assessed,
            $medias,
            $contradicts === 1 ? 'contradice' : 'contradicen',
        );

        return $explain('contradicts', [
            'step' => $sentence,
            'sentence' => $sentence,
            'confidenceDelta' => $dismissive ? self::CONFIDENCE_DELTA_CONTRADICTS : -self::CONFIDENCE_DELTA_CONTRADICTS,
            'riskDelta' => self::RISK_DELTA_CONTRADICTS,
            'keyFactors' => $keyFactors,
        ], $confirms, $contradicts, $visibleThreats, $dismissive);
    }
}
