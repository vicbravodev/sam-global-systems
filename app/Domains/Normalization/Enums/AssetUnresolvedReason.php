<?php

namespace App\Domains\Normalization\Enums;

/**
 * Por qué un evento normalizado quedó sin unidad (`asset_id` null). Se guarda
 * en `payload_normalized_json.asset_unresolved_reason` y viaja como señal
 * `asset_unresolved_reason` al contexto, a la IA y al motor de decisiones.
 *
 * Nunca lleva el id externo ni el activo ajeno: `ForeignAssetRejected` sólo
 * dice que la referencia existía pero pertenece a otro tenant.
 */
enum AssetUnresolvedReason: string
{
    /** El payload trae un id de vehículo que el tenant no tiene registrado. */
    case UnknownExternalId = 'unknown_external_id';

    /** El payload no trae ningún id de vehículo. */
    case NoVehicleInPayload = 'no_vehicle_in_payload';

    /** El id apunta a un activo de otro tenant y se rechazó por aislamiento. */
    case ForeignAssetRejected = 'foreign_asset_rejected';

    public function label(): string
    {
        return match ($this) {
            self::UnknownExternalId => 'Vehículo no registrado en la cuenta',
            self::NoVehicleInPayload => 'El evento no trae vehículo',
            self::ForeignAssetRejected => 'Vehículo de otra cuenta rechazado',
        };
    }
}
