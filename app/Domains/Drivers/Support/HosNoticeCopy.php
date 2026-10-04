<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;

/**
 * Lo que SAM le dice al chofer sobre sus horas de servicio, en español de
 * México, de tú y con la voz de SAM (mismo estilo que IncidentNoticeCopy).
 *
 *  - `subject`: título del aviso (variable {{1}} de la plantilla de WhatsApp).
 *  - `body`: texto de la app de Samsara, WhatsApp o SMS. Empieza con "SAM:"
 *    y cabe en un SMS (≤ 160).
 *  - `spoken`: lo que lee la llamada. Saluda y va sin abreviaturas ni
 *    inglés ("descanso de media hora", no "break de 30 min").
 *
 * Nunca lleva el nombre del chofer ni datos de la unidad: le llega a él.
 */
final class HosNoticeCopy
{
    /**
     * @param  int|null  $amount  minutos que le quedan (avisos previos) u horas (ciclo)
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function for(HosNotice $notice, ?int $amount = null): array
    {
        $minutes = self::minutes($amount);
        $spokenMinutes = self::spokenMinutes($amount);
        $hours = self::hours($amount);
        $spokenHours = self::spokenHours($amount);
        $warnTeam = 'Si no, vamos a avisar a tu equipo de monitoreo.';

        return match ($notice) {
            HosNotice::BreakLead => self::copy(
                "Tu break de 30 min es en {$minutes}",
                "Te quedan {$minutes} para tu break obligatorio de 30 min. Busca dónde parar con seguridad.",
                "Te quedan {$spokenMinutes} para tu descanso obligatorio de media hora. Busca dónde parar con seguridad.",
            ),
            HosNotice::BreakLimit => self::copy(
                'Ya te toca tu break de 30 min',
                'Ya te toca tu break obligatorio de 30 min. Para en cuanto puedas hacerlo con seguridad.',
                'Ya te toca tu descanso obligatorio de media hora. Por favor detente en cuanto puedas hacerlo con seguridad.',
            ),
            HosNotice::BreakInsist => self::copy(
                'Sigues sin tu break de 30 min',
                'Sigues manejando sin tu break de 30 min. Detente ya en un lugar seguro; si no, avisaremos a tu equipo de monitoreo.',
                "Sigues manejando sin tu descanso de media hora. Por favor detente ya en un lugar seguro. {$warnTeam}",
            ),
            HosNotice::DriveLead => self::copy(
                "Tus 11 h de manejo se acaban en {$minutes}",
                "Se te acaban tus 11 h de manejo en {$minutes}. Planea tu parada para tu descanso de 10 h.",
                "Se te acaban tus 11 horas de manejo en {$spokenMinutes}. Planea tu parada para tu descanso de 10 horas.",
            ),
            HosNotice::DriveLimit => self::copy(
                'Se acabaron tus 11 h de manejo',
                'Ya se acabaron tus 11 h de manejo. Para en cuanto puedas con seguridad y toma tu descanso de 10 h.',
                'Ya se acabaron tus 11 horas de manejo. Por favor detente en cuanto puedas hacerlo con seguridad y toma tu descanso de 10 horas.',
            ),
            HosNotice::DriveInsist => self::copy(
                'Sigues manejando sin horas disponibles',
                'Sigues manejando sin horas de manejo disponibles. Detente ya en un lugar seguro; si no, avisaremos a tu equipo de monitoreo.',
                "Sigues manejando y ya no tienes horas de manejo disponibles. Por favor detente ya en un lugar seguro. {$warnTeam}",
            ),
            HosNotice::ShiftLead => self::copy(
                "Tu turno de 14 h termina en {$minutes}",
                "Tu turno de 14 h termina en {$minutes}. Planea tu parada para tu descanso de 10 h.",
                "Tu turno de 14 horas termina en {$spokenMinutes}. Planea tu parada para tu descanso de 10 horas.",
            ),
            HosNotice::ShiftLimit => self::copy(
                'Se acabó tu turno de 14 h',
                'Ya se acabó tu turno de 14 h. Ya no puedes manejar: para con seguridad y toma tu descanso de 10 h.',
                'Ya se acabó tu turno de 14 horas y ya no puedes manejar. Por favor detente en cuanto puedas hacerlo con seguridad y toma tu descanso de 10 horas.',
            ),
            HosNotice::ShiftInsist => self::copy(
                'Sigues fuera de tu turno de 14 h',
                'Sigues trabajando fuera de tu turno de 14 h. Detente ya en un lugar seguro; si no, avisaremos a tu equipo de monitoreo.',
                "Sigues trabajando fuera de tu turno de 14 horas. Por favor detente ya en un lugar seguro. {$warnTeam}",
            ),
            HosNotice::CycleLead => self::copy(
                "Te quedan {$hours} en tu ciclo de 70 h",
                "Te quedan {$hours} en tu ciclo de 70 h. Vas a necesitar tu reinicio de 34 h.",
                "Te quedan {$spokenHours} en tu ciclo de 70 horas. Vas a necesitar tu reinicio de 34 horas.",
            ),
            HosNotice::RestComplete => self::copy(
                'Ya cumpliste tu descanso',
                'Ya cumpliste tu descanso. Cuando estés listo, puedes retomar tu ruta.',
                'Ya cumpliste tu descanso. Cuando estés listo, puedes retomar tu ruta. Buen viaje.',
            ),
            HosNotice::Violation => self::copy(
                'Tus horas de servicio marcan una infracción',
                'Tus horas de servicio marcan una infracción. Para en cuanto puedas con seguridad; ya avisamos a tu equipo de monitoreo.',
                'Tus horas de servicio marcan una infracción. Por favor detente en cuanto puedas hacerlo con seguridad. Ya avisamos a tu equipo de monitoreo.',
            ),
        };
    }

    /** Línea de tiempo del incidente cuando el chofer corrige. */
    public static function corrected(HosSituation $situation): string
    {
        return match ($situation) {
            HosSituation::BreakDue => 'El chofer ya tomó su break de 30 min.',
            HosSituation::DriveLimit, HosSituation::ShiftLimit => 'El chofer ya se detuvo para su descanso de 10 h.',
            HosSituation::CycleLimit => 'El ciclo de 70 h del chofer ya tiene horas disponibles.',
            HosSituation::RestComplete => 'El chofer ya retomó su ruta.',
            HosSituation::Violation => 'Los relojes del chofer ya no marcan infracción.',
        };
    }

    /**
     * @return array{subject: string, body: string, spoken: string}
     */
    private static function copy(string $subject, string $body, string $spoken): array
    {
        return [
            'subject' => $subject,
            'body' => 'SAM: '.$body,
            'spoken' => 'Hola, te llama SAM. '.$spoken,
        ];
    }

    private static function minutes(?int $amount): string
    {
        return max(1, $amount ?? 1).' min';
    }

    private static function spokenMinutes(?int $amount): string
    {
        $n = max(1, $amount ?? 1);

        return $n === 1 ? '1 minuto' : "{$n} minutos";
    }

    private static function hours(?int $amount): string
    {
        return max(1, $amount ?? 1).' h';
    }

    private static function spokenHours(?int $amount): string
    {
        $n = max(1, $amount ?? 1);

        return $n === 1 ? '1 hora' : "{$n} horas";
    }
}
