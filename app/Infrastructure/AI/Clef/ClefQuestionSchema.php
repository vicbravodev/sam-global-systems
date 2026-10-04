<?php

namespace App\Infrastructure\AI\Clef;

use App\Domains\AI\Enums\MediaAssessmentResult;

/**
 * Preguntas tipadas que se le hacen a Clef. Cambiar textos u opciones exige
 * subir VERSION: el reporte compara sólo filas de la misma versión.
 */
final class ClefQuestionSchema
{
    public const int VERSION = 1;

    public const int SEVERITY_LEVELS = 5;

    /** @var list<string> */
    public const array CLASSIFICATIONS = ['real_event', 'false_positive', 'noise', 'duplicate', 'unclear'];

    /** @var list<string> */
    public const array MEDIA_SIGNALS = ['driver_visible', 'passenger_detected', 'visible_threat', 'cabin_appears_normal', 'vehicle_moving'];

    private const array SIGNAL_INSTRUCTIONS = [
        'driver_visible' => '¿Se ve al conductor en las imágenes?',
        'passenger_detected' => '¿Hay algún pasajero u otra persona además del conductor?',
        'visible_threat' => '¿Se ve una amenaza (arma, agresión, persona forzando la unidad)?',
        'cabin_appears_normal' => '¿La cabina se ve en condiciones normales?',
        'vehicle_moving' => '¿El vehículo parece estar en movimiento?',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function for(bool $withImages): array
    {
        $questions = [
            'classification' => [
                'type' => 'choice',
                'instructions' => 'Eres el monitor de seguridad de una flota de transporte. Con el evento reportado por el proveedor telemático, su contexto (telemetría, ubicación, historial reciente) y las imágenes si las hay, decide qué es este evento.',
                'criteria' => [
                    'real_event' => 'El evento ocurrió y requiere atención según la evidencia.',
                    'false_positive' => 'El proveedor reportó algo que la evidencia contradice: no ocurrió.',
                    'noise' => 'Señal técnica sin relevancia operativa (falla momentánea, ruido del dispositivo).',
                    'duplicate' => 'Repite un evento ya reportado del mismo activo hace poco.',
                    'unclear' => 'La evidencia no alcanza para decidir; necesita revisión humana.',
                ],
            ],
            'severity' => [
                'type' => 'score',
                'instructions' => '¿Qué tan grave es la situación para la seguridad del conductor, la carga o terceros?',
                'criteria' => ['Ninguna', 'Baja', 'Media', 'Alta', 'Crítica'],
            ],
            'needs_human_now' => [
                'type' => 'noul',
                'instructions' => '¿Un operador de monitoreo debe revisar este evento de inmediato?',
            ],
        ];

        if (! $withImages) {
            return $questions;
        }

        $mediaCriteria = [];

        foreach (MediaAssessmentResult::cases() as $case) {
            $mediaCriteria[$case->value] = self::mediaResultCriterion($case);
        }

        $questions['media_result'] = [
            'type' => 'choice',
            'instructions' => '¿Qué muestran las imágenes respecto al evento reportado?',
            'criteria' => $mediaCriteria,
        ];

        foreach (self::MEDIA_SIGNALS as $signal) {
            $questions[$signal] = [
                'type' => 'choice',
                'instructions' => self::SIGNAL_INSTRUCTIONS[$signal],
                'criteria' => ['si' => 'Sí', 'no' => 'No', 'no_visible' => 'No se puede determinar con las imágenes'],
            ];
        }

        $questions['persons_visible'] = [
            'type' => 'choice',
            'instructions' => '¿Cuántas personas se ven en total?',
            'criteria' => ['0' => 'Ninguna', '1' => 'Una', '2' => 'Dos', '3_o_mas' => 'Tres o más'],
        ];

        return $questions;
    }

    private static function mediaResultCriterion(MediaAssessmentResult $case): string
    {
        return match ($case) {
            MediaAssessmentResult::ConfirmsEvent => 'Las imágenes confirman el evento.',
            MediaAssessmentResult::ContradictsEvent => 'Las imágenes contradicen el evento.',
            MediaAssessmentResult::Inconclusive => 'Las imágenes no permiten confirmar ni descartar.',
            MediaAssessmentResult::LowQuality => 'Las imágenes son de muy baja calidad para juzgar.',
            MediaAssessmentResult::Unavailable => 'Las imágenes no están disponibles o no aplican.',
        };
    }
}
