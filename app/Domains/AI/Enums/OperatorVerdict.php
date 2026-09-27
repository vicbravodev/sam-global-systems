<?php

namespace App\Domains\AI\Enums;

/**
 * Etiqueta humana (human-in-the-loop) que el operador asigna a la
 * evaluación de IA de un evento.
 */
enum OperatorVerdict: string
{
    case Confirmed = 'confirmed';
    case FalsePositive = 'false_positive';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmado por operador',
            self::FalsePositive => 'Falso positivo (operador)',
        };
    }

    /**
     * ¿Coincide la clasificación de la IA con el veredicto del operador?
     *
     * `confirmed` concuerda con `real_event`; `false_positive` concuerda con
     * cualquier clasificación que descarte el evento (falso positivo, ruido o
     * duplicado). `unclear`/`pending_evidence` nunca concuerdan.
     */
    public function agreesWith(?EventClassification $classification): bool
    {
        return match ($this) {
            self::Confirmed => $classification === EventClassification::RealEvent,
            self::FalsePositive => in_array($classification, [
                EventClassification::FalsePositive,
                EventClassification::Noise,
                EventClassification::Duplicate,
            ], true),
        };
    }
}
