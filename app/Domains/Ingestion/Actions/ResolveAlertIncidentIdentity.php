<?php

namespace App\Domains\Ingestion\Actions;

use Carbon\CarbonImmutable;

/**
 * Identidad estable de un incidente de alerta de Samsara (`AlertIncident`),
 * compartida por las dos vías por las que llega: el webhook (el incidente va
 * en `data`) y el poll de respaldo `GET /alerts/incidents/stream` (el mismo
 * objeto, suelto). El `eventId` del webhook identifica la ENTREGA, no el
 * incidente, y el poll no lo tiene: deduplicar por él dejaría pasar el mismo
 * pánico dos veces.
 *
 * La identidad es el HECHO, no la alerta: unidad (vehículo o conductor) +
 * disparadores (`triggerId`) + instante. Un mismo botón de pánico dispara
 * todas las alertas de Samsara con trigger 1034 (la de SAM y las propias del
 * cliente), cada una con su `configurationId` e `incidentUrl`: identificar
 * por la alerta lo contaba como N pánicos (N incidentes si llegan abiertos)
 * y avisaba de un webhook roto por las alertas que no apuntan a SAM.
 *
 * Sin disparador o sin unidad no se puede saber si dos alertas son el mismo
 * hecho, y se cae a la identidad por alerta: `incidentUrl` + `happenedAtTime`
 * (se vio la misma URL con instantes distintos) o configuración + instante.
 * La clave lleva además el estado (`open`/`resolved`) para que la resolución
 * en origen siga pasando como actualización, igual que antes con
 * `eventId:estado`.
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
        return self::eventFingerprint($incident) ?? self::alertFingerprint($incident);
    }

    /**
     * Qué identifica la huella: `event` (unidad + disparadores + instante,
     * vale entre alertas), `alert` (la alerta concreta) o null (sin huella).
     *
     * @param  array<mixed>  $incident
     */
    public function scope(array $incident): ?string
    {
        if (self::eventFingerprint($incident) !== null) {
            return 'event';
        }

        return self::alertFingerprint($incident) !== null ? 'alert' : null;
    }

    /**
     * @param  array<mixed>  $incident
     */
    private static function eventFingerprint(array $incident): ?string
    {
        $subject = self::subject($incident);
        $triggers = self::triggers($incident);
        $instant = self::instant(self::string($incident['happenedAtTime'] ?? null));

        if ($subject === null || $triggers === [] || $instant === null) {
            return null;
        }

        return sha1('event|'.$subject.'|'.implode(',', $triggers).'|'.$instant);
    }

    /**
     * @param  array<mixed>  $incident
     */
    private static function alertFingerprint(array $incident): ?string
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

        return sha1('cfg|'.$configurationId.'|'.$happenedAt.'|'.(self::subject($incident) ?? ''));
    }

    /**
     * `triggerId` de las condiciones, ordenados y sin repetir.
     *
     * @param  array<mixed>  $incident
     * @return list<int>
     */
    private static function triggers(array $incident): array
    {
        $conditions = $incident['conditions'] ?? null;

        if (! is_array($conditions)) {
            return [];
        }

        $triggers = [];

        foreach ($conditions as $condition) {
            $triggerId = is_array($condition) ? ($condition['triggerId'] ?? null) : null;

            if (is_int($triggerId)) {
                $triggers[$triggerId] = $triggerId;
            }
        }

        ksort($triggers);

        return array_values($triggers);
    }

    /**
     * El instante en UTC con milisegundos: `18:18:20Z` y `18:18:20.000Z` son
     * el mismo pánico venga por donde venga.
     */
    private static function instant(?string $happenedAt): ?string
    {
        if ($happenedAt === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($happenedAt)->utc()->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Unidad (o, si no hay, conductor) del primer `details` que la traiga, en
     * cualquier condición y con cualquier disparador (`panicButton`,
     * `tamperingDetected`, …).
     *
     * @param  array<mixed>  $incident
     */
    private static function subject(array $incident): ?string
    {
        $conditions = $incident['conditions'] ?? null;

        if (! is_array($conditions)) {
            return null;
        }

        foreach ($conditions as $condition) {
            $details = is_array($condition) ? ($condition['details'] ?? null) : null;

            if (! is_array($details)) {
                continue;
            }

            foreach ($details as $detail) {
                $subject = is_array($detail)
                    ? self::string($detail['vehicle']['id'] ?? null) ?? self::string($detail['driver']['id'] ?? null)
                    : null;

                if ($subject !== null) {
                    return $subject;
                }
            }
        }

        return null;
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
