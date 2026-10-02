<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\MessagingAddressSuppression;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Lista de supresión por (canal, dirección), de plataforma:
 *
 * - Twilio respondió un error que dice que ese canal nunca llegará a esa
 *   dirección (STOP 21610, fijo 21614/30006, sin WhatsApp 63003/63024): se
 *   suprime y el siguiente aviso no lo intenta (va directo a los demás
 *   canales).
 * - El destinatario escribió STOP/BAJA: se suprime; START/ALTA lo levanta.
 *
 * Política (decisión 2026-10-02): la supresión es de plataforma y vale
 * también para avisos críticos de cualquier tenant — el remitente es
 * compartido y Twilio mismo rechaza (21610) el envío a quien dio STOP; un
 * fijo o un número sin WhatsApp no recibirá por ese canal de nadie. Sólo
 * se suprime el canal afectado: la voz, la app y el correo siguen, y el
 * fallback los usa. Hacia el tenant nunca se expone el motivo (ni que hubo
 * un STOP): la entrega omitida dice sólo "address unavailable".
 *
 * Nunca registra la dirección en el log.
 */
final class MessagingSuppressions
{
    /**
     * Código de Twilio → canales que deja inservibles y motivo.
     *
     * @var array<int|string, array{channels: list<string>, reason: string}>
     */
    public const array SUPPRESSING_CODES = [
        '21610' => ['channels' => ['sms'], 'reason' => 'opted_out'],
        '21614' => ['channels' => ['sms'], 'reason' => 'not_mobile'],
        '30006' => ['channels' => ['sms'], 'reason' => 'landline'],
        '63003' => ['channels' => ['whatsapp'], 'reason' => 'no_whatsapp'],
        '63024' => ['channels' => ['whatsapp'], 'reason' => 'no_whatsapp'],
    ];

    /** @var list<string> */
    public const array OPT_OUT_KEYWORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'BAJA'];

    /** @var list<string> */
    public const array OPT_IN_KEYWORDS = ['START', 'UNSTOP', 'ALTA'];

    /**
     * Sólo `+` y dígitos: "+52 (55) 1234-5678" y "whatsapp:+525512345678"
     * son la misma dirección.
     */
    public static function normalize(string $address): string
    {
        return (string) preg_replace('/[^\d+]/', '', (string) preg_replace('/^whatsapp:/i', '', trim($address)));
    }

    /**
     * Motivo si la dirección está suprimida para el canal, o null.
     */
    public static function reasonFor(ChannelType $type, string $address): ?string
    {
        if (! in_array($type, [ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Voice], true)) {
            return null;
        }

        return MessagingAddressSuppression::query()
            ->where('channel_type', $type->value)
            ->where('address', self::normalize($address))
            ->value('reason');
    }

    /**
     * Un fallo del proveedor que deja el canal inservible para esa dirección.
     */
    public static function recordFromError(ChannelType $type, ?string $address, ?string $errorCode): void
    {
        $rule = $errorCode !== null ? (self::SUPPRESSING_CODES[$errorCode] ?? null) : null;

        if ($rule === null || $address === null || $address === '' || ! in_array($type->value, $rule['channels'], true)) {
            return;
        }

        self::suppress($type, $address, $rule['reason'], 'provider_error', $errorCode);
    }

    public static function suppress(ChannelType $type, string $address, string $reason, string $source, ?string $errorCode = null): void
    {
        $address = self::normalize($address);
        $input = ['channel_type' => $type->value, 'source' => $source];

        try {
            $row = MessagingAddressSuppression::query()->firstOrCreate(
                ['channel_type' => $type->value, 'address' => $address],
                ['reason' => $reason, 'provider_error_code' => $errorCode, 'source' => $source, 'suppressed_at' => now()],
            );
        } catch (UniqueConstraintViolationException) {
            SystemLog::skipped('notifications.suppression.recorded', reason: 'already_suppressed', input: $input);

            return;
        }

        if (! $row->wasRecentlyCreated) {
            SystemLog::skipped('notifications.suppression.recorded', reason: 'already_suppressed', input: $input);

            return;
        }

        SystemLog::ok('notifications.suppression.recorded', input: $input, calc: [
            'reason' => $reason,
            'provider_error_code' => LoggableCode::guard($errorCode),
        ], result: ['suppression_id' => $row->id]);
    }

    public static function lift(ChannelType $type, string $address): bool
    {
        $deleted = MessagingAddressSuppression::query()
            ->where('channel_type', $type->value)
            ->where('address', self::normalize($address))
            ->delete();

        SystemLog::ok('notifications.suppression.lifted', input: ['channel_type' => $type->value], result: ['lifted' => $deleted > 0]);

        return $deleted > 0;
    }
}
