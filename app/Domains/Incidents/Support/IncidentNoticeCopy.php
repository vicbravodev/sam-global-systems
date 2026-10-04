<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Models\Incident;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Los textos que SAM manda a la gente sobre un incidente, en español de
 * México claro y sin tecnicismos (nada de "SLA", "acknowledgement" ni
 * "on-call").
 *
 * Cada aviso trae tres versiones:
 *  - `subject`: asunto del correo y título del aviso en la app. Lleva la
 *    unidad completa (número económico y placas).
 *  - `body`: el SMS/WhatsApp. Cabe en un solo SMS junto con las instrucciones
 *    de respuesta (AppendReplyInstructions): dice qué pasó, en qué unidad (el
 *    número económico) y, si se sabe, quién la maneja.
 *  - `spoken`: lo que se lee en una llamada. Saluda, va sin placas ni ligas y
 *    con el número económico separado para que la voz lo diga bien.
 */
final class IncidentNoticeCopy
{
    /**
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function created(Incident $incident): array
    {
        $driver = self::driverShortName($incident);
        $who = $driver !== null ? ", conductor {$driver}" : '';
        $spokenWho = $driver !== null ? ", que maneja {$driver}" : '';

        return [
            'subject' => self::headline($incident, self::unit($incident), 'en la unidad'),
            'body' => 'SAM: '.self::headline($incident, self::shortUnit($incident), 'en la unidad').$who.'.',
            'spoken' => 'Hola, te llama SAM. Hay una alerta de '.self::what($incident).self::spokenWhere($incident, 'en la unidad').$spokenWho.'. Por favor revísala en SAM lo antes posible.',
        ];
    }

    /**
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function onCallAssigned(Incident $incident): array
    {
        return [
            'subject' => 'Estás de guardia: te toca atender '.self::what($incident).self::where(self::unit($incident), 'en la unidad'),
            'body' => 'SAM: Estás de guardia y te asignamos esto: '.self::headline($incident, self::shortUnit($incident), 'en la unidad').'.',
            'spoken' => 'Hola, te llama SAM. Estás de guardia y te asignamos una alerta de '.self::what($incident).self::spokenWhere($incident, 'en la unidad').'. Por favor revísala en SAM.',
        ];
    }

    /**
     * Alguien del equipo te asignó un incidente.
     *
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function assigned(Incident $incident): array
    {
        return [
            'subject' => 'Te asignaron: '.self::headline($incident, self::unit($incident), 'en la unidad'),
            'body' => 'SAM: Te asignaron esto: '.self::headline($incident, self::shortUnit($incident), 'en la unidad').'.',
            'spoken' => 'Hola, te llama SAM. Te asignaron una alerta de '.self::what($incident).self::spokenWhere($incident, 'en la unidad').'. Por favor revísala en SAM.',
        ];
    }

    /**
     * Nadie ha atendido el incidente dentro del tiempo acordado.
     *
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function unattended(Incident $incident, ?CarbonInterface $now = null): array
    {
        $minutes = self::minutesOpen($incident, $now);
        $elapsed = $minutes === 1 ? '1 minuto' : "{$minutes} minutos";

        return [
            'subject' => 'Nadie lo ha atendido: '.self::headline($incident, self::unit($incident), 'en'),
            'body' => 'SAM: '.self::headline($incident, self::shortUnit($incident), 'en')." lleva {$elapsed} sin que nadie lo atienda.",
            'spoken' => 'Hola, te llama SAM. La alerta de '.self::what($incident).self::spokenWhere($incident, 'de la unidad')." lleva {$elapsed} sin que nadie la atienda. Necesitamos que alguien la revise ya.",
        ];
    }

    /**
     * Quien contestó la llamada de verificación confirmó que es real.
     *
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function emergencyConfirmed(Incident $incident): array
    {
        return [
            'subject' => 'Emergencia confirmada: '.self::headline($incident, self::unit($incident), 'en'),
            'body' => 'SAM: Emergencia real confirmada: '.self::what($incident).self::where(self::shortUnit($incident), 'en').'. Atiéndela ya.',
            'spoken' => 'Hola, te llama SAM. Confirmaron por teléfono que la alerta de '.self::what($incident).self::spokenWhere($incident, 'de la unidad').' es una emergencia real. Por favor atiéndela de inmediato.',
        ];
    }

    /**
     * Nadie contestó la llamada de verificación.
     *
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function verificationUnanswered(Incident $incident): array
    {
        return [
            'subject' => 'Nadie contestó para verificar: '.self::headline($incident, self::unit($incident), 'en'),
            'body' => 'SAM: '.self::headline($incident, self::shortUnit($incident), 'en').': nadie contestó la verificación. Atiéndelo ya.',
            'spoken' => 'Hola, te llama SAM. Llamamos para verificar la alerta de '.self::what($incident).self::spokenWhere($incident, 'de la unidad').' y nadie contestó. Por favor atiéndela de inmediato.',
        ];
    }

    /**
     * No se pudo ni intentar la llamada de verificación.
     *
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function verificationUnavailable(Incident $incident): array
    {
        return [
            'subject' => 'No pudimos verificar: '.self::headline($incident, self::unit($incident), 'en'),
            'body' => 'SAM: '.self::headline($incident, self::shortUnit($incident), 'en').': no pudimos verificarlo. Atiéndelo ya.',
            'spoken' => 'Hola, te llama SAM. No pudimos llamar para verificar la alerta de '.self::what($incident).self::spokenWhere($incident, 'de la unidad').'. Por favor atiéndela de inmediato.',
        ];
    }

    /**
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function becameCritical(Incident $incident): array
    {
        return [
            'subject' => 'Ahora es urgente: '.self::headline($incident, self::unit($incident), 'en'),
            'body' => 'SAM: '.self::headline($incident, self::shortUnit($incident), 'en').' subió a prioridad crítica. Atiéndelo ya.',
            'spoken' => 'Hola, te llama SAM. La alerta de '.self::what($incident).self::spokenWhere($incident, 'de la unidad').' subió a prioridad crítica. Por favor atiéndela de inmediato.',
        ];
    }

    /**
     * Qué pasó, en minúsculas para ir a media frase ("botón de pánico").
     * El título del incidente es "Botón de pánico — T-77 JC PZ4388A": se
     * toma lo que va antes de la raya.
     */
    public static function what(Incident $incident): string
    {
        $what = trim(Str::before(trim($incident->title ?? ''), ' — '));

        return $what !== '' ? Str::lcfirst($what) : 'un incidente';
    }

    /**
     * La unidad tal como la conoce la flota (número económico y placas).
     */
    public static function unit(Incident $incident): ?string
    {
        self::load($incident, 'asset');
        $name = trim($incident->asset->name ?? $incident->asset->code ?? '');

        return $name !== '' ? $name : null;
    }

    /**
     * Sólo el número económico ("T-77 JC PZ4388A" → "T-77"). Si el primer
     * pedazo no trae número ("Unidad 12"), el nombre va completo.
     */
    public static function shortUnit(Incident $incident): ?string
    {
        $unit = self::unit($incident);

        if ($unit === null) {
            return null;
        }

        $economic = Str::before($unit, ' ');

        return preg_match('/\d/', $economic) === 1 ? $economic : $unit;
    }

    /**
     * El número económico para leerlo en voz alta ("T-77" → "T 77"): el
     * guion se pronunciaría como "menos" y las placas no ayudan.
     */
    public static function spokenUnit(Incident $incident): ?string
    {
        $unit = self::shortUnit($incident);

        return $unit !== null ? trim(str_replace(['-', '_'], ' ', $unit)) : null;
    }

    /**
     * Nombre y apellido paterno, con mayúscula inicial: Samsara manda
     * "JESUS IVAN NOLASCO GARCIA" y en un SMS cabe "Jesus Nolasco".
     */
    public static function driverShortName(Incident $incident): ?string
    {
        self::load($incident, 'driver');
        $full = trim($incident->driver->full_name ?? '');

        if ($full === '') {
            return null;
        }

        $words = preg_split('/\s+/u', Str::title(mb_strtolower($full)));
        $words = $words === false ? [] : $words;
        $count = count($words);

        // Nombre(s) + apellido paterno + materno: el paterno es el penúltimo.
        return $count <= 2 ? implode(' ', $words) : $words[0].' '.$words[$count - 2];
    }

    /**
     * "Botón de pánico en la unidad T-77", con mayúscula inicial.
     */
    private static function headline(Incident $incident, ?string $unit, string $preposition): string
    {
        return Str::ucfirst(self::what($incident)).self::where($unit, $preposition);
    }

    private static function where(?string $unit, string $preposition): string
    {
        return $unit !== null ? " {$preposition} {$unit}" : '';
    }

    private static function spokenWhere(Incident $incident, string $preposition): string
    {
        return self::where(self::spokenUnit($incident), $preposition);
    }

    /**
     * Carga la relación dentro del tenant del incidente: estos textos se
     * arman también desde jobs y webhooks, sin sesión.
     */
    private static function load(Incident $incident, string $relation): void
    {
        if (! $incident->relationLoaded($relation)) {
            TenantContext::for($incident->team_id, fn () => $incident->load($relation));
        }
    }

    private static function minutesOpen(Incident $incident, ?CarbonInterface $now): int
    {
        $openedAt = $incident->opened_at ?? $incident->created_at;

        if ($openedAt === null) {
            return 1;
        }

        $minutes = (int) floor(Carbon::instance($openedAt)->diffInSeconds($now ?? now(), true) / 60);

        return max(1, $minutes);
    }
}
