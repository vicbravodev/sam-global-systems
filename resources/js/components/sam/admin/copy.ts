/** Textos y catálogos propios de la consola de super-admin. */

export const NOTIFICATION_PROVIDER_LABELS: Record<string, string> = {
    twilio: 'Twilio',
    webpush: 'Web Push',
    slack: 'Slack',
    webhook: 'Webhook',
    mail: 'Correo SAM',
};

export const FEATURE_SOURCE_LABELS: Record<string, string> = {
    default_plan: 'Del plan',
    manual_override: 'Manual',
    promo: 'Promoción',
    beta_access: 'Beta',
};
