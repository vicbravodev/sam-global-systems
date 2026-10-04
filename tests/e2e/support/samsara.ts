import { createHmac, randomUUID } from 'node:crypto';
import type { APIRequestContext, APIResponse } from '@playwright/test';

/** E2eSeeder::WEBHOOK_URL / WEBHOOK_SECRET. */
export const WEBHOOK_PATH = '/api/webhooks/e2e-samsara';

export const WEBHOOK_SECRET = 'e2e-webhook-secret-not-real';

export interface PanicOptions {
    vehicle?: string;
    driver?: string;
    /** Llave con la que se firma (por omisión, la sembrada). */
    secret?: string;
}

/**
 * AlertIncident de botón de pánico con la forma exacta que manda Samsara
 * (ver database/fixtures/samsara-panic-events.json), con datos sintéticos.
 */
export function panicPayload({
    vehicle = 'E2E-01',
    driver = 'Chofer E2E',
}: PanicOptions = {}): Record<string, unknown> {
    const now = new Date().toISOString();

    return {
        orgId: 1,
        eventId: randomUUID(),
        eventTime: now,
        eventType: 'AlertIncident',
        webhookId: 'e2e',
        data: {
            conditions: [
                {
                    details: {
                        panicButton: {
                            driver: { id: 'e2e-driver', name: driver },
                            vehicle: {
                                id: 'e2e-vehicle',
                                name: vehicle,
                                serial: 'E2E0001',
                            },
                        },
                    },
                    triggerId: 1034,
                    description: 'Panic Button',
                },
            ],
            isResolved: false,
            happenedAtTime: now,
            updatedAtTime: now,
            configurationId: 'e2e-config',
        },
    };
}

/**
 * Manda un pánico firmado como lo firma Samsara: HMAC-SHA256 de
 * `v1:{timestamp}:{cuerpo}` en `X-Samsara-Signature`.
 */
export function sendPanic(
    request: APIRequestContext,
    options: PanicOptions = {},
): Promise<APIResponse> {
    const body = JSON.stringify(panicPayload(options));
    const timestamp = String(Date.now());
    const signature = createHmac('sha256', options.secret ?? WEBHOOK_SECRET)
        .update(`v1:${timestamp}:${body}`)
        .digest('hex');

    return request.post(WEBHOOK_PATH, {
        data: body,
        headers: {
            'Content-Type': 'application/json',
            'X-Samsara-Signature': `v1=${signature}`,
            'X-Samsara-Timestamp': timestamp,
        },
    });
}
