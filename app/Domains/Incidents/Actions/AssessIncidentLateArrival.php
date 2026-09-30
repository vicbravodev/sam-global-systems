<?php

namespace App\Domains\Incidents\Actions;

use App\Contracts\TenantConfig\TenantScheduleResolver;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Mide cuánto tarde se abre un incidente respecto a cuándo ocurrió su evento
 * (retraso = apertura − `occurred_at`). Un pánico rescatado por
 * `ReprocessStuckRawEventsJob` horas después, un webhook atrasado o un poller
 * con cursor viejo abren su incidente "ahora": sin este aviso el operador lo
 * atiende como si acabara de pasar.
 *
 * Por debajo del umbral (`incidents.late_notice_after_minutes`) devuelve el
 * cálculo con `late = false` y no toca nada más: el camino rápido sólo paga
 * una resta. Por encima resuelve la zona del tenant y el raw event (¿llegó
 * tarde o se procesó tarde? ¿vino de un rescate?) y arma los textos del aviso.
 */
class AssessIncidentLateArrival
{
    public const int DEFAULT_THRESHOLD_MINUTES = 10;

    public const string FALLBACK_TIMEZONE = 'America/Mexico_City';

    public function __construct(
        private readonly TenantScheduleResolver $scheduleResolver,
    ) {}

    /**
     * @return array{late: bool, reason: string|null, calc: array<string, mixed>, notice: array<string, mixed>|null}
     */
    public function assess(NormalizedEvent $event, DateTimeInterface $openedAt): array
    {
        $thresholdMinutes = max(0, (int) config('incidents.late_notice_after_minutes', self::DEFAULT_THRESHOLD_MINUTES));
        $opened = CarbonImmutable::instance($openedAt);

        if ($event->occurred_at === null) {
            return [
                'late' => false,
                'reason' => 'no_occurred_at',
                'calc' => ['threshold_minutes' => $thresholdMinutes, 'opened_at' => $opened->toIso8601String()],
                'notice' => null,
            ];
        }

        $occurred = CarbonImmutable::instance($event->occurred_at);
        $delaySeconds = (int) $occurred->diffInSeconds($opened, false);

        $calc = [
            'occurred_at' => $occurred->toIso8601String(),
            'opened_at' => $opened->toIso8601String(),
            'delay_seconds' => $delaySeconds,
            'threshold_minutes' => $thresholdMinutes,
            'threshold_seconds' => $thresholdMinutes * 60,
        ];

        if ($delaySeconds <= $thresholdMinutes * 60) {
            return ['late' => false, 'reason' => 'within_threshold', 'calc' => $calc, 'notice' => null];
        }

        $teamId = (int) $event->team_id;
        [$timezone, $timezoneSource] = $this->timezone($teamId, $opened);

        $raw = $event->raw_event_id !== null
            ? RawEvent::query()->where('team_id', $teamId)->whereKey($event->raw_event_id)->first()
            : null;

        $reprocessAttempts = (int) ($raw?->reprocess_attempts ?? 0);
        $receivedAt = $raw?->received_at !== null ? CarbonImmutable::instance($raw->received_at) : null;
        $receiveDelaySeconds = $receivedAt !== null ? (int) $occurred->diffInSeconds($receivedAt, false) : null;

        // Llegó tarde si el proveedor nos lo entregó pasado el umbral; si llegó
        // a tiempo y aun así se abrió tarde, el retraso fue nuestro (cola,
        // fallo, rescate).
        $cause = $receiveDelaySeconds !== null && $receiveDelaySeconds > $thresholdMinutes * 60
            ? 'received_late'
            : 'processed_late';

        $localOccurred = $occurred->setTimezone($timezone);
        $localOpened = $opened->setTimezone($timezone);
        $localTime = $localOccurred->isSameDay($localOpened)
            ? $localOccurred->format('H:i')
            : $localOccurred->format('d/m H:i');

        $ago = self::humanDuration($delaySeconds);
        $rescued = $reprocessAttempts > 0;

        $notice = [
            'delay_seconds' => $delaySeconds,
            'threshold_minutes' => $thresholdMinutes,
            'occurred_at' => $occurred->toIso8601String(),
            'occurred_at_local' => $localTime,
            'received_at' => $receivedAt?->toIso8601String(),
            'receive_delay_seconds' => $receiveDelaySeconds,
            'opened_at' => $opened->toIso8601String(),
            'timezone' => $timezone,
            'timezone_source' => $timezoneSource,
            'cause' => $cause,
            'rescued' => $rescued,
            'reprocess_attempts' => $reprocessAttempts,
            'ago' => $ago,
            'text' => $this->text($ago, $localTime, $rescued),
            'spoken' => $this->spoken($delaySeconds, $localTime, $rescued),
            'timeline_title' => $this->timelineTitle($ago, $cause, $rescued, $reprocessAttempts),
            'timeline_description' => $this->timelineDescription($localTime, $timezone, $receivedAt?->setTimezone($timezone), $localOpened),
        ];

        return [
            'late' => true,
            'reason' => null,
            'calc' => $calc + [
                'timezone' => $timezone,
                'timezone_source' => $timezoneSource,
                'receive_delay_seconds' => $receiveDelaySeconds,
                'cause' => $cause,
                'reprocess_attempts' => $reprocessAttempts,
                'rescued' => $rescued,
            ],
            'notice' => $notice,
        ];
    }

    /**
     * "9 h 5 min", "25 min", "2 d 3 h".
     */
    public static function humanDuration(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        if ($days > 0) {
            return $hours > 0 ? "{$days} d {$hours} h" : "{$days} d";
        }

        if ($hours > 0) {
            return $mins > 0 ? "{$hours} h {$mins} min" : "{$hours} h";
        }

        return "{$mins} min";
    }

    /**
     * Zona del tenant: perfil de horario activo → `teams.timezone` →
     * `billing.timezone` (la zona comercial de la plataforma).
     *
     * @return array{0: string, 1: 'schedule_profile'|'team'|'billing_default'}
     */
    private function timezone(int $teamId, CarbonImmutable $at): array
    {
        $schedule = $this->scheduleResolver->resolve($teamId, $at);

        if ($schedule->isPersisted && self::validTimezone($schedule->timezone)) {
            return [$schedule->timezone, 'schedule_profile'];
        }

        $teamTimezone = Team::query()->whereKey($teamId)->value('timezone');

        if (is_string($teamTimezone) && self::validTimezone($teamTimezone)) {
            return [$teamTimezone, 'team'];
        }

        $fallback = (string) config('billing.timezone', self::FALLBACK_TIMEZONE);

        return [self::validTimezone($fallback) ? $fallback : self::FALLBACK_TIMEZONE, 'billing_default'];
    }

    private static function validTimezone(?string $timezone): bool
    {
        if ($timezone === null || $timezone === '') {
            return false;
        }

        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function text(string $ago, string $localTime, bool $rescued): string
    {
        $text = "⚠️ Ocurrió hace {$ago} (hora local {$localTime})";

        return $rescued ? "{$text} — recuperado por reproceso automático" : $text;
    }

    /**
     * Versión para la voz (TTS): sin emoji ni abreviaturas.
     */
    private function spoken(int $delaySeconds, string $localTime, bool $rescued): string
    {
        $minutes = intdiv(max(0, $delaySeconds), 60);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = $days === 1 ? '1 día' : "{$days} días";
        }

        if ($hours > 0) {
            $parts[] = $hours === 1 ? '1 hora' : "{$hours} horas";
        }

        if ($mins > 0 && $days === 0) {
            $parts[] = $mins === 1 ? '1 minuto' : "{$mins} minutos";
        }

        $duration = $parts === [] ? 'menos de un minuto' : implode(' y ', $parts);
        $text = "Atención: este evento ocurrió hace {$duration}, a las {$localTime} hora local.";

        return $rescued ? "{$text} Se recuperó por reproceso automático." : $text;
    }

    private function timelineTitle(string $ago, string $cause, bool $rescued, int $attempts): string
    {
        $how = $cause === 'received_late' ? 'recibido con retraso' : 'procesado con retraso';

        if ($rescued) {
            $times = $attempts === 1 ? '1 reproceso' : "{$attempts} reprocesos";
            $how .= " tras rescate automático ({$times})";
        }

        return "Evento ocurrido hace {$ago}; {$how}";
    }

    private function timelineDescription(string $localTime, string $timezone, ?CarbonImmutable $localReceived, CarbonImmutable $localOpened): string
    {
        $received = $localReceived !== null
            ? ($localReceived->isSameDay($localOpened) ? $localReceived->format('H:i') : $localReceived->format('d/m H:i'))
            : 'sin registro';

        return "Ocurrió a las {$localTime} ({$timezone}); llegó a SAM a las {$received}; incidente abierto a las {$localOpened->format('H:i')}.";
    }
}
