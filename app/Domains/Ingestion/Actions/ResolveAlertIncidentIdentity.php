<?php

namespace App\Domains\Ingestion\Actions;

/**
 * Identidad estable de un incidente de alerta de Samsara (`AlertIncident`),
 * compartida por las dos vías por las que llega: el webhook (el incidente va
 * en `data`) y el poll de respaldo `GET /alerts/incidents/stream` (el mismo
 * objeto, suelto). El `eventId` del webhook identifica la ENTREGA, no el
 * incidente, y el poll no lo tiene: deduplicar por él dejaría pasar el mismo
 * pánico dos veces.
 *
 * La identidad es `incidentUrl` + `happenedAtTime` (Samsara arma la URL con
 * la configuración, el vehículo y el instante, pero se vio la misma URL con
 * instantes distintos) o, si falta la URL, configuración + instante +
 * vehículo/conductor. La clave lleva además el estado (`open`/`resolved`)
 * para que la resolución en origen siga pasando como actualización, igual
 * que antes con `eventId:estado`.
 *
 * Claves con {@see PREFIX} son de identidad del PROVEEDOR: valen entre
 * fuentes (webhook y poll) del mismo tenant, y así las trata
 * {@see DetectDuplicateEvent}. Nunca entre tenants.
 */
class ResolveAlertIncidentIdentity
{
    public const string PREFIX = 'alert_incident:';

    /**
     * Huella del incidente (sin estado), o null si el objeto no trae con qué
     * identificarlo.
     *
     * @param  array<mixed>  $incident  El objeto de incidente (`data` del webhook o un item del stream).
     */
    public function fingerprint(array $incident): ?string
    {
        $url = self::string($incident['incidentUrl'] ?? null);
        $happenedAt = self::string($incident['happenedAtTime'] ?? null);

        // El instante va en la huella aunque la URL ya lo codifique: en
        // simulaciones la misma URL llegó con varios `happenedAtTime`, y
        // fusionar dos pánicos distintos es peor que no deduplicar uno.
        if ($url !== null) {
            return sha1('url|'.$url.'|'.($happenedAt ?? ''));
        }

        $configurationId = self::string($incident['configurationId'] ?? null);

        if ($configurationId === null || $happenedAt === null) {
            return null;
        }

        $details = $incident['conditions'][0]['details'] ?? null;
        $subject = null;

        if (is_array($details)) {
            foreach ($details as $detail) {
                if (is_array($detail)) {
                    $subject = self::string($detail['vehicle']['id'] ?? null) ?? self::string($detail['driver']['id'] ?? null);
                }

                if ($subject !== null) {
                    break;
                }
            }
        }

        return sha1('cfg|'.$configurationId.'|'.$happenedAt.'|'.($subject ?? ''));
    }

    /**
     * Clave de dedup con estado, o null si el objeto no trae identidad.
     *
     * @param  array<mixed>  $incident
     */
    public function key(array $incident): ?string
    {
        $fingerprint = $this->fingerprint($incident);

        if ($fingerprint === null) {
            return null;
        }

        return self::PREFIX.$fingerprint.':'.self::state($incident);
    }

    /**
     * Todas las claves posibles del mismo incidente (cualquier estado).
     *
     * @return list<string>
     */
    public function keysForFingerprint(string $fingerprint): array
    {
        return [self::PREFIX.$fingerprint.':open', self::PREFIX.$fingerprint.':resolved'];
    }

    public static function isIdentityKey(?string $key): bool
    {
        return $key !== null && str_starts_with($key, self::PREFIX);
    }

    /**
     * @param  array<mixed>  $incident
     */
    public static function state(array $incident): string
    {
        return ($incident['isResolved'] ?? null) === true ? 'resolved' : 'open';
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
