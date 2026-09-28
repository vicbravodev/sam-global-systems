<?php

namespace App\Domains\Copilot\Support;

use App\Contracts\AI\CopilotNarrator;
use App\Domains\Copilot\Data\CopilotAnswer;
use App\Domains\Copilot\Data\CopilotNarration;
use App\Domains\Copilot\Enums\CopilotIntent;

/**
 * Deterministic narrator used when no language model is configured (and as
 * the fallback when the provider fails): it stitches the tools' own grounded
 * highlights together. No tokens are consumed.
 */
final class TemplateCopilotNarrator implements CopilotNarrator
{
    public function narrate(string $question, CopilotAnswer $answer, array $history = []): CopilotNarration
    {
        $highlights = $answer->highlights();

        if ($answer->intent === CopilotIntent::General && $highlights === []) {
            return new CopilotNarration(
                'Puedo responder sobre ubicación de unidades y remolques, reportes completos de una unidad, '
                .'la última media de cámaras, estadísticas de motor, combustible, botones de pánico, '
                .'incidentes abiertos y ranking de conductores. Elige una unidad con el selector o prueba: '
                .'«Dame el reporte de la unidad T555».',
            );
        }

        return new CopilotNarration(implode(' ', $highlights));
    }
}
