<?php

namespace App\Domains\Normalization\Events;

use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un evento del proveedor que SAM ya normalizó cambió en origen (un safety
 * event revisado, re-etiquetado o descartado en Samsara) y su fila se
 * actualizó en sitio. A diferencia de {@see EventNormalized}, no vuelve a
 * correr contexto, media ni IA: sólo avisa del cambio.
 */
class NormalizedEventUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NormalizedEvent $normalizedEvent,
        public readonly ?string $previousState,
    ) {}
}
