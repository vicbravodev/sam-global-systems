import { router } from '@inertiajs/react';
import type { ConfigField } from './types';

// Las credenciales de Twilio son env de plataforma (TWILIO_*), nunca config
// de canal: un canal Twilio sólo sobreescribe valores no secretos; vacío =
// default de plataforma (TWILIO_SMS_FROM / _WHATSAPP_FROM / _VOICE_FROM).
export const CONFIG_FIELDS: Record<string, ConfigField[]> = {
    sms: [
        {
            key: 'from',
            label: 'Emisor (opcional)',
            hint: 'Número E.164 o Messaging Service (MG…). Con MG, un envío con timeout no se puede rastrear en Twilio y cae al reintento normal.',
        },
    ],
    whatsapp: [
        {
            key: 'from',
            label: 'Emisor (opcional)',
            hint: 'Formato whatsapp:+52…',
        },
        {
            key: 'content_sid',
            label: 'Plantilla aprobada (Content SID)',
            hint: 'Plantilla con {{1}} asunto y {{2}} cuerpo. Sin ella se envía texto libre, que Twilio rechaza fuera de la ventana de 24 h.',
        },
    ],
    voice: [
        {
            key: 'from',
            label: 'Número de voz (opcional)',
            hint: 'E.164. Vacío = número de plataforma.',
        },
        {
            key: 'ring_timeout_seconds',
            label: 'Segundos de timbrado',
            hint: 'Por defecto 25.',
            kind: 'number',
        },
    ],
    // Sin config por canal: las llaves VAPID viven en env de plataforma.
    push: [],
    slack: [
        { key: 'slack_webhook_url', label: 'Webhook de Slack', kind: 'secret' },
    ],
    webhook: [
        { key: 'url', label: 'URL destino' },
        { key: 'secret', label: 'Secreto HMAC', kind: 'secret' },
    ],
    email: [],
    web: [],
};

export const providerFor = (type: string): string =>
    type === 'sms' || type === 'whatsapp' || type === 'voice'
        ? 'twilio'
        : type === 'push'
          ? 'webpush'
          : type === 'slack'
            ? 'slack'
            : type === 'webhook'
              ? 'webhook'
              : 'mail';

export function visit(method: 'put' | 'delete', url: string, data = {}) {
    return new Promise<void>((resolve) => {
        const options = { preserveScroll: true, onFinish: () => resolve() };

        if (method === 'delete') {
            router.delete(url, options);
        } else {
            router.put(url, data, options);
        }
    });
}
