/**
 * Tonos semánticos de estado: la única escala de color para "cómo va algo"
 * fuera de la severidad (que tiene la suya en `SeverityBadge`) y del estado de
 * incidente (`StatusPill`). Todo mapa `valor → color` de una feature se
 * expresa como `valor → { label, tone }` y pinta con estas clases o con
 * `StatusBadge`; nunca con clases de color sueltas.
 *
 * Construidos sobre los tokens de `resources/css/app.css` (`--severity-*`,
 * `--primary`, `--fg-*`). `ok` usa `severity-low` (no `health-ok`, que queda
 * para la salud de infraestructura: tiempo real, integraciones en vivo).
 */
export type Tone =
    | 'ok'
    | 'warn'
    | 'high'
    | 'critical'
    | 'info'
    | 'neutral'
    | 'primary';

/** Texto coloreado (valores, cifras, etiquetas sueltas). */
export const TONE_TEXT: Record<Tone, string> = {
    ok: 'text-severity-low',
    warn: 'text-severity-medium',
    high: 'text-severity-high',
    critical: 'text-severity-critical',
    info: 'text-severity-info',
    neutral: 'text-fg-3',
    primary: 'text-primary',
};

/** Punto de estado (`size-1.5 rounded-full` + esta clase). */
export const TONE_DOT: Record<Tone, string> = {
    ok: 'bg-severity-low',
    warn: 'bg-severity-medium',
    high: 'bg-severity-high',
    critical: 'bg-severity-critical',
    info: 'bg-severity-info',
    neutral: 'bg-fg-3',
    primary: 'bg-primary',
};

/** Píldora: borde, fondo tenue y texto (lo que pinta `StatusBadge`). */
export const TONE_PILL: Record<Tone, string> = {
    ok: 'border-severity-low/40 bg-severity-low/10 text-severity-low',
    warn: 'border-severity-medium/40 bg-severity-medium/10 text-severity-medium',
    high: 'border-severity-high/40 bg-severity-high/10 text-severity-high',
    critical:
        'border-severity-critical/40 bg-severity-critical/10 text-severity-critical',
    info: 'border-severity-info/40 bg-severity-info/10 text-severity-info',
    neutral: 'border-border bg-surface-3 text-fg-3',
    primary: 'border-primary/30 bg-primary/10 text-primary',
};

/** Superficie de aviso (tarjeta o banner): borde y fondo, sin texto. */
export const TONE_SURFACE: Record<Tone, string> = {
    ok: 'border-severity-low/30 bg-severity-low/10',
    warn: 'border-severity-medium/30 bg-severity-medium/10',
    high: 'border-severity-high/30 bg-severity-high/10',
    critical: 'border-severity-critical/30 bg-severity-critical/10',
    info: 'border-severity-info/30 bg-severity-info/10',
    neutral: 'border-border bg-surface-2',
    primary: 'border-primary/30 bg-primary/10',
};

/** Borde izquierdo de acento (filas y tarjetas). */
export const TONE_BORDER_L: Record<Tone, string> = {
    ok: 'border-l-severity-low',
    warn: 'border-l-severity-medium',
    high: 'border-l-severity-high',
    critical: 'border-l-severity-critical',
    info: 'border-l-severity-info',
    neutral: 'border-l-border',
    primary: 'border-l-primary',
};

/** Color como variable CSS, para lienzos que no usan clases (mapas, SVG). */
export const TONE_VAR: Record<Tone, string> = {
    ok: 'var(--severity-low)',
    warn: 'var(--severity-medium)',
    high: 'var(--severity-high)',
    critical: 'var(--severity-critical)',
    info: 'var(--severity-info)',
    neutral: 'var(--fg-3)',
    primary: 'var(--primary)',
};

/** Etiqueta y tono de un valor de dominio: la forma de todo mapa de estados. */
export interface ToneLabel {
    label: string;
    tone: Tone;
}

/**
 * Clase del punto para un código que llega como string (opciones de filtro):
 * `undefined` si el mapa no lo conoce, para no pintar un punto engañoso.
 */
export function toneDotFor(
    map: Readonly<Record<string, ToneLabel>>,
    code: string,
): string | undefined {
    const entry: ToneLabel | undefined = map[code];

    return entry ? TONE_DOT[entry.tone] : undefined;
}
