<?php

namespace App\Domains\Assets\Enums;

/**
 * Si SAM vigila un activo. Es la unidad de cobro: sólo `monitored` se
 * sondea, se normaliza, se evalúa con IA, alerta y cuenta tracto-días.
 */
enum AssetMonitoringState: string
{
    /** Vigilado y facturado por cada día en este estado. */
    case Monitored = 'monitored';

    /** Descubierto por el sync; el cliente decide si lo enciende. */
    case Pending = 'pending';

    /** Baja explícita: sigue en inventario pero SAM lo ignora. */
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Monitored => 'Vigilado',
            self::Pending => 'Sin vigilar',
            self::Excluded => 'Excluido',
        };
    }
}
