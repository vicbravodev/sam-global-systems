<?php

namespace App\Infrastructure\AI\Clef;

/**
 * Convierte el snapshot que vio GPT en el estado que ve Clef. Quita lo que
 * le daría la respuesta: el veredicto visual de otro modelo
 * (`media_assessments`; Clef ve las imágenes por sí mismo) y el veredicto
 * humano que viaja en reevaluaciones (`recent_history.operator_feedback`).
 */
class ClefStateBuilder
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function fromSnapshot(array $snapshot): array
    {
        unset($snapshot['media_assessments']);

        if (is_array($snapshot['recent_history'] ?? null)) {
            unset($snapshot['recent_history']['operator_feedback']);
        }

        return $snapshot;
    }
}
