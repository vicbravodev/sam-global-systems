<?php

namespace App\Domains\Notifications\Enums;

/**
 * Estado de una entrega por canal.
 *
 * Canales síncronos (email, web, push, slack, webhook): éxito del driver =
 * `Delivered`. Canales Twilio (SMS, WhatsApp, voz) avanzan con el feedback
 * del proveedor (status callbacks + reconciliador):
 *
 *   - `Queued`    Twilio aceptó el envío (hay SID) — aún sin salir.
 *   - `Sending`   en curso (llamada `initiated`/`ringing`, mensaje `sending`).
 *   - `Sent`      entregado al operador/carrier, sin confirmación final.
 *   - `Delivered` mensaje `delivered`/`read`, o llamada contestada.
 *   - `Failed`    mensaje `failed`/`undelivered`, llamada `no-answer`/`busy`/`failed`/`canceled`.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Retrying = 'retrying';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Bounced, self::Cancelled, self::Skipped], true);
    }

    /**
     * La entrega salió (o va saliendo) sin error: cuenta como "destinatario
     * alcanzado" a efectos de estado agregado y de no disparar fallbacks.
     */
    public function isReached(): bool
    {
        return in_array($this, [self::Queued, self::Sent, self::Delivered], true);
    }

    /**
     * Orden de progreso de un envío con feedback del proveedor. Un evento con
     * rango menor o igual al actual es tardío/duplicado y no hace retroceder
     * la entrega (p.ej. `sent` llegando después de `delivered`).
     */
    public function progressRank(): int
    {
        return match ($this) {
            self::Pending, self::Retrying => 0,
            self::Queued => 1,
            self::Sending => 2,
            self::Sent => 3,
            self::Delivered, self::Failed, self::Bounced, self::Cancelled, self::Skipped => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Queued => 'En cola',
            self::Sending => 'Enviando',
            self::Sent => 'Enviado al operador',
            self::Delivered => 'Entregado',
            self::Failed => 'No entregado',
            self::Bounced => 'Rebotado',
            self::Retrying => 'Reintentando',
            self::Cancelled => 'Cancelado',
            self::Skipped => 'Omitido',
        };
    }
}
