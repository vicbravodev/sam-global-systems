import type { NotificationDeliveryRow } from '@/types/notifications';

export function groupByRecipient(
    deliveries: NotificationDeliveryRow[],
): { key: number; name: string; rows: NotificationDeliveryRow[] }[] {
    const groups = new Map<
        number,
        { key: number; name: string; rows: NotificationDeliveryRow[] }
    >();

    for (const delivery of deliveries) {
        const key = delivery.recipient.id;
        const group = groups.get(key) ?? {
            key,
            name: delivery.recipient.name ?? 'Destinatario sin nombre',
            rows: [],
        };

        group.rows.push(delivery);
        groups.set(key, group);
    }

    return [...groups.values()];
}
