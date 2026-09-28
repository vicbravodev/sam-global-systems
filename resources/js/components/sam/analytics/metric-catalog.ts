import { formatNumber, formatPercent } from '@/lib/format';
import { kpiLabel } from '@/lib/labels';
import type { MetricRow } from './types';

/** Qué dirección de cambio es buena noticia para el operador. */
export type Better = 'up' | 'down' | 'neutral';

export interface MetricCopy {
    title: string;
    /** Una frase: qué significa la cifra, sin jerga. */
    explain: string;
    better: Better;
}

/**
 * Nombre y explicación en lenguaje de operación de cada KPI diario. El código
 * nunca se muestra; si llega uno nuevo sin copy, cae al nombre del catálogo.
 */
export const METRIC_COPY: Record<string, MetricCopy> = {
    incidents_total: {
        title: 'Incidentes nuevos',
        explain: 'Cuántos incidentes se abrieron en el periodo.',
        better: 'down',
    },
    incidents_resolved: {
        title: 'Incidentes resueltos',
        explain:
            'De los incidentes abiertos en el periodo, cuántos ya se cerraron.',
        better: 'neutral',
    },
    incidents_open: {
        title: 'Siguen abiertos',
        explain: 'Incidentes del periodo que todavía nadie ha cerrado.',
        better: 'down',
    },
    incidents_mttr_minutes: {
        title: 'Tiempo medio de resolución',
        explain:
            'Cuánto tardamos en promedio en cerrar un incidente desde que se abre.',
        better: 'down',
    },
    ai_accuracy_rate: {
        title: 'Acierto de la IA',
        explain:
            'De cada 100 decisiones de la IA, cuántas no tuvo que corregir una persona.',
        better: 'up',
    },
    ai_human_override_rate: {
        title: 'Corregidas por una persona',
        explain: 'Decisiones de la IA que un operador cambió después.',
        better: 'down',
    },
    ai_average_confidence: {
        title: 'Seguridad de la IA',
        explain:
            'Qué tan segura estaba la IA, en promedio, al clasificar cada evento.',
        better: 'up',
    },
    ai_false_positive_rate: {
        title: 'Falsas alarmas filtradas',
        explain:
            'Eventos que la IA reconoció como falsa alarma antes de molestar a nadie.',
        better: 'neutral',
    },
    ai_real_event_rate: {
        title: 'Eventos reales',
        explain:
            'Eventos que la IA confirmó como una situación real que atender.',
        better: 'neutral',
    },
    ai_total_evaluations: {
        title: 'Eventos revisados por la IA',
        explain: 'Cuántos eventos de tus unidades analizó la IA.',
        better: 'neutral',
    },
    ai_evaluations_total: {
        title: 'Eventos revisados por la IA',
        explain: 'Cuántos eventos de tus unidades analizó la IA.',
        better: 'neutral',
    },
    decisions_total: {
        title: 'Decisiones tomadas',
        explain:
            'Veces que el sistema decidió qué hacer con un evento: abrir incidente, avisar o descartar.',
        better: 'neutral',
    },
    decisions_human_review_rate: {
        title: 'Enviadas a revisión humana',
        explain:
            'Decisiones en las que el sistema pidió que una persona confirmara.',
        better: 'down',
    },
    ingested_events: {
        title: 'Eventos recibidos',
        explain:
            'Avisos que llegaron de tus proveedores de telemetría, como Samsara.',
        better: 'neutral',
    },
    ai_calls: {
        title: 'Consultas a la IA',
        explain:
            'Veces que se pidió a la IA analizar algo. Cuentan para tu cuota mensual.',
        better: 'neutral',
    },
    outbound_notifications: {
        title: 'Avisos enviados',
        explain:
            'Mensajes enviados a tu equipo por WhatsApp, SMS, correo o llamada.',
        better: 'neutral',
    },
    copilot_queries: {
        title: 'Preguntas a SAM Copilot',
        explain: 'Preguntas que tu equipo le hizo al asistente.',
        better: 'neutral',
    },
    active_assets: {
        title: 'Unidades activas',
        explain:
            'Unidades dadas de alta en tu flota, vigiladas o no, al último día del periodo.',
        better: 'neutral',
    },
};

export function metricCopy(metric: MetricRow): MetricCopy {
    return (
        METRIC_COPY[metric.code] ?? {
            title: kpiLabel(metric.code, metric.name),
            explain: '',
            better: 'neutral',
        }
    );
}

/** Proporciones 0..1 que se muestran como porcentaje. */
const RATIO_UNITS = new Set(['ratio', 'score', 'rate']);

export function isRatio(unit: string | null): boolean {
    return unit !== null && RATIO_UNITS.has(unit);
}

/** "45 min", "2 h 10 min", "1 d 3 h". */
export function formatMinutes(value: number): string {
    const minutes = Math.round(value);

    if (minutes < 60) {
        return `${minutes} min`;
    }

    if (minutes < 1440) {
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;

        return m === 0 ? `${h} h` : `${h} h ${m} min`;
    }

    const d = Math.floor(minutes / 1440);
    const h = Math.round((minutes % 1440) / 60);

    return h === 0 ? `${d} d` : `${d} d ${h} h`;
}

export function formatMetricValue(
    value: number | null,
    unit: string | null,
): string {
    if (value === null) {
        return '—';
    }

    if (isRatio(unit)) {
        return formatPercent(value, { digits: 0 });
    }

    if (unit === 'minutes') {
        return formatMinutes(value);
    }

    return formatNumber(value, { maximumFractionDigits: 0 });
}

/** Qué representa el número grande según cómo se agrega el periodo. */
export const AGGREGATION_HINT: Record<MetricRow['aggregation'], string> = {
    sum: 'en el periodo',
    avg: 'promedio del periodo',
    latest: 'al cierre del periodo',
};

/** Índice por código para leer una métrica concreta. */
export function byCode(metrics: MetricRow[]): Map<string, MetricRow> {
    return new Map(metrics.map((metric) => [metric.code, metric]));
}
