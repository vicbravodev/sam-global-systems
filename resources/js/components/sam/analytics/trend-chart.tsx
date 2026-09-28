import { useEffect, useRef, useState } from 'react';
import { Bars, LineChart } from '@/components/sam/charts';
import { formatNumber } from '@/lib/format';
import { formatMetricValue, isRatio } from './metric-catalog';
import type { MetricPoint } from './types';

/** Ancho real del contenedor, para dibujar el SVG 1:1 (texto legible). */
function useElementWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const node = ref.current;

        if (node === null) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(Math.floor(entry.contentRect.width)),
        );
        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return [ref, width] as const;
}

const SHORT_DAY = new Intl.DateTimeFormat('es-MX', {
    day: 'numeric',
    month: 'short',
});

function shortDay(date: string): string {
    const [y, m, d] = date.split('-').map(Number);

    return SHORT_DAY.format(new Date(y, m - 1, d)).replace('.', '');
}

/** Etiquetas de fecha espaciadas (~1 cada 90 px); ninguna pegada al borde derecho, donde se cortaría. */
function sparseLabels(points: MetricPoint[], width: number): string[] {
    const slots = Math.max(2, Math.floor(width / 90));
    const step = Math.max(1, Math.ceil((points.length - 1) / (slots - 1)));

    return points.map((point, i) =>
        i % step === 0 && i / (points.length - 1) <= 0.9
            ? shortDay(point.date)
            : '',
    );
}

interface Props {
    points: MetricPoint[];
    unit: string | null;
    /** Nombre de la métrica para lectores de pantalla. */
    label: string;
    color?: string;
    height?: number;
}

/**
 * Tendencia diaria de una métrica: barras para conteos, línea para
 * proporciones y tiempos. Debajo, el día más alto y el más bajo en palabras
 * para quien no lee gráficas.
 */
export function TrendChart({
    points,
    unit,
    label,
    color = 'var(--chart-1)',
    height = 120,
}: Props) {
    const [ref, width] = useElementWidth<HTMLDivElement>();

    if (points.length < 2) {
        return (
            <p className="rounded-md bg-surface-2 px-3 py-4 text-center text-2xs text-fg-3">
                Hace falta más de un día con datos para dibujar la tendencia.
            </p>
        );
    }

    const ratio = isRatio(unit);
    const data = points.map((point) =>
        ratio ? Math.round(point.value * 1000) / 10 : point.value,
    );
    const labels = width > 0 ? sparseLabels(points, width) : undefined;
    const ariaLabel = `Tendencia diaria de ${label}`;

    const max = points.reduce((a, b) => (b.value > a.value ? b : a));
    const min = points.reduce((a, b) => (b.value < a.value ? b : a));

    return (
        <div className="flex flex-col gap-1.5">
            <div ref={ref} className="w-full">
                {width > 0 &&
                    (unit === 'count' ? (
                        <Bars
                            data={data}
                            labels={labels}
                            width={width}
                            height={height}
                            color={color}
                            valueFmt={(v) =>
                                formatNumber(v, { maximumFractionDigits: 0 })
                            }
                            aria-label={ariaLabel}
                        />
                    ) : (
                        <LineChart
                            series={[{ data, color }]}
                            labels={labels}
                            width={width}
                            height={height}
                            aria-label={ariaLabel}
                        />
                    ))}
            </div>
            <p className="text-2xs text-fg-3">
                {ratio
                    ? 'Porcentaje'
                    : unit === 'minutes'
                      ? 'Minutos'
                      : 'Total'}{' '}
                por día · más alto {formatMetricValue(max.value, unit)} el{' '}
                {shortDay(max.date)} · más bajo{' '}
                {formatMetricValue(min.value, unit)} el {shortDay(min.date)}
            </p>
        </div>
    );
}
