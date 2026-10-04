import { durationLabel } from '@/lib/time';

const CAMERA_LABEL: Record<string, string> = {
    road: 'Camino',
    driver: 'Cabina',
};

/**
 * Etiqueta de una foto de contexto (la que la cámara tomó por su cuenta cerca
 * del evento): qué cámara y a qué distancia del evento, para que nadie la lea
 * como el evento mismo. "Cabina · 1 s después", "Camino · 2 min antes".
 */
export function contextCaptureLabel(
    camera: string | null | undefined,
    offsetSeconds: number | null | undefined,
): string {
    const parts = [CAMERA_LABEL[camera ?? ''] ?? 'Foto de contexto'];

    if (offsetSeconds !== null && offsetSeconds !== undefined) {
        parts.push(
            offsetSeconds === 0
                ? 'al momento'
                : `${durationLabel(Math.abs(offsetSeconds))} ${offsetSeconds < 0 ? 'antes' : 'después'}`,
        );
    }

    return parts.join(' · ');
}

/**
 * Por qué una galería está vacía, según lo que pasó con la búsqueda de media:
 * buscando, la cámara no tenía nada, o nunca se pidió.
 */
export function emptyMediaMessage(
    requestStatuses: Array<string | null>,
): string {
    if (
        requestStatuses.some((status) =>
            ['pending', 'sent', 'processing'].includes(status ?? ''),
        )
    ) {
        return 'Buscando fotos y video en la cámara de la unidad…';
    }

    if (requestStatuses.length > 0) {
        return 'La cámara no tenía fotos ni video de este momento (unidad apagada, sin señal o cámara dormida).';
    }

    return 'Sin media disponible para este evento.';
}
