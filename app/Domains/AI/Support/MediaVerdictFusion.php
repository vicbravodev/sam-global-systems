<?php

namespace App\Domains\AI\Support;

/**
 * Fusión determinista del veredicto visual en la evaluación del evento.
 *
 * Recibe los veredictos por media (los mismos que viajan en
 * `AIInputContext::$mediaAssessments`) y produce un ajuste transparente y
 * acotado: un paso de razonamiento en español, una oración para la
 * explicación, deltas de confianza/riesgo y contadores para key_factors.
 * Solo actúa cuando hay veredictos decisivos (confirma/contradice); con
 * media inconclusa o sin media no cambia nada.
 */
class MediaVerdictFusion
{
    private const CONFIDENCE_DELTA_CONTRADICTS = -0.15;

    private const RISK_DELTA_CONTRADICTS = -0.15;

    private const CONFIDENCE_DELTA_CONFIRMS = 0.10;

    private const RISK_DELTA_CONFIRMS = 0.10;

    /**
     * @param  list<array<string, mixed>>  $mediaAssessments
     * @return array{step: string, sentence: string, confidenceDelta: float, riskDelta: float, keyFactors: array<string, int>}|null
     */
    public function fuse(array $mediaAssessments): ?array
    {
        $assessed = count($mediaAssessments);

        if ($assessed === 0) {
            return null;
        }

        $results = array_count_values(array_map(
            fn (array $assessment): string => (string) ($assessment['result'] ?? ''),
            $mediaAssessments,
        ));

        $contradicts = (int) ($results['contradicts_event'] ?? 0);
        $confirms = (int) ($results['confirms_event'] ?? 0);

        if ($contradicts === 0 && $confirms === 0) {
            return null;
        }

        $keyFactors = [
            'media_assessed_count' => $assessed,
            'media_confirms_count' => $confirms,
            'media_contradicts_count' => $contradicts,
        ];

        if ($contradicts > $confirms) {
            $sentence = sprintf(
                'Análisis visual: %d de %d %s evaluadas contradicen el evento.',
                $contradicts,
                $assessed,
                $assessed === 1 ? 'media' : 'medias',
            );

            return [
                'step' => $sentence,
                'sentence' => $sentence,
                'confidenceDelta' => self::CONFIDENCE_DELTA_CONTRADICTS,
                'riskDelta' => self::RISK_DELTA_CONTRADICTS,
                'keyFactors' => $keyFactors,
            ];
        }

        if ($confirms > $contradicts) {
            $sentence = sprintf(
                'Análisis visual: %d de %d %s evaluadas confirman el evento.',
                $confirms,
                $assessed,
                $assessed === 1 ? 'media' : 'medias',
            );

            return [
                'step' => $sentence,
                'sentence' => $sentence,
                'confidenceDelta' => self::CONFIDENCE_DELTA_CONFIRMS,
                'riskDelta' => self::RISK_DELTA_CONFIRMS,
                'keyFactors' => $keyFactors,
            ];
        }

        // Empate de veredictos decisivos: se informa sin mover los números.
        $sentence = sprintf(
            'Análisis visual: veredictos divididos (%d contradicen, %d confirman de %d evaluadas).',
            $contradicts,
            $confirms,
            $assessed,
        );

        return [
            'step' => $sentence,
            'sentence' => $sentence,
            'confidenceDelta' => 0.0,
            'riskDelta' => 0.0,
            'keyFactors' => $keyFactors,
        ];
    }
}
