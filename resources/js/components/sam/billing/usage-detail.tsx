import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { formatNumber } from '@/lib/format';
import { featureLabel, meterLabel } from '@/lib/labels';
import { TONE_PILL } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type { FeatureRow, UsageRow } from './types';

/**
 * Medidores que ya están en el desglose del mes (vigilancia, IA, tope y
 * mensajería a costo) o que son puramente técnicos (tokens de IA): aquí
 * repetirían o confundirían cifras.
 */
const HIDDEN_METERS = new Set([
    'monitored_asset_days',
    'monitored_assets',
    'ai_calls',
    'ai_tokens_in',
    'ai_tokens_out',
]);

/** Nombres de cliente para lo que el medidor cuenta. */
const ACTIVITY_LABELS: Record<string, string> = {
    ingested_events: 'Eventos recibidos de tus unidades',
    media_requests: 'Videos solicitados a las cámaras',
    voice_calls: 'Llamadas de verificación',
    otp_sms_sent: 'SMS de verificación de teléfono',
    outbound_notifications: 'Avisos enviados',
    incident_workflows: 'Incidentes atendidos',
    automation_actions: 'Acciones automáticas',
    active_cameras: 'Cámaras activas',
};

function usageNote(row: UsageRow): { text: string; tone: 'fg' | 'warn' } {
    if (row.billedVia === 'messaging') {
        return { text: 'Se cobra en Mensajería y llamadas', tone: 'fg' };
    }

    if (row.billed && row.included > 0) {
        if (row.overageCharged && row.overage > 0) {
            return {
                text: `${formatNumber(row.overage)} por encima de ${formatNumber(row.included)} incluidos: se cobran como extra`,
                tone: 'warn',
            };
        }

        return {
            text: `Incluidos hasta ${formatNumber(row.included)}`,
            tone: 'fg',
        };
    }

    return { text: 'Sin costo', tone: 'fg' };
}

/**
 * Actividad del mes que no forma una línea propia de la factura. Plegado
 * por defecto: el cliente viene a ver cuánto paga, no a leer medidores.
 */
export function UsageDetail({
    usage,
    features,
}: {
    usage: UsageRow[];
    features: FeatureRow[];
}) {
    const [open, setOpen] = useState(false);
    const rows = usage.filter(
        (row) => row.amount === null && !HIDDEN_METERS.has(row.meterCode ?? ''),
    );

    if (rows.length === 0 && features.length === 0) {
        return null;
    }

    return (
        <section className="rounded-lg border border-border bg-surface-1">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-2"
            >
                <span className="min-w-0">
                    <span className="sam-h4 block">Ver detalle de consumo</span>
                    <span className="block text-xs text-fg-3">
                        Actividad de tu operación este mes. Salvo lo indicado,
                        no se cobra aparte.
                    </span>
                </span>
                <ChevronDown
                    className={cn(
                        'size-4 shrink-0 text-fg-3 transition-transform',
                        open && 'rotate-180',
                    )}
                    aria-hidden="true"
                />
            </button>

            {open && (
                <div className="flex flex-col gap-4 border-t border-border px-4 py-4">
                    {rows.length > 0 && (
                        <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                            {rows.map((row) => {
                                const note = usageNote(row);
                                const code = row.meterCode ?? '';
                                const ratio =
                                    row.billed && row.included > 0
                                        ? Math.min(
                                              1,
                                              row.consumed / row.included,
                                          )
                                        : null;

                                return (
                                    <li
                                        key={code}
                                        className="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                                    >
                                        <span className="min-w-0 text-xs text-fg-1">
                                            {ACTIVITY_LABELS[code] ??
                                                meterLabel(
                                                    row.meterCode,
                                                    row.meterName,
                                                )}
                                        </span>
                                        <span className="flex items-center gap-3">
                                            <span
                                                className="hidden h-1 w-24 shrink-0 overflow-hidden rounded-full bg-surface-3 data-[empty=true]:invisible sm:block"
                                                data-empty={ratio === null}
                                                aria-hidden="true"
                                            >
                                                <span
                                                    className={cn(
                                                        'block h-full rounded-full',
                                                        note.tone === 'warn'
                                                            ? 'bg-severity-medium'
                                                            : 'bg-primary',
                                                    )}
                                                    style={{
                                                        width: `${Math.max(2, (ratio ?? 0) * 100)}%`,
                                                    }}
                                                />
                                            </span>
                                            <span className="text-xs font-medium text-fg-1 tabular-nums sm:w-16 sm:text-right">
                                                {formatNumber(row.consumed)}
                                            </span>
                                            <span
                                                className={cn(
                                                    'text-2xs sm:w-52',
                                                    note.tone === 'warn'
                                                        ? 'text-severity-medium'
                                                        : 'text-fg-3',
                                                )}
                                            >
                                                {note.text}
                                            </span>
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {features.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <span className="sam-caps">
                                Módulos de tu servicio
                            </span>
                            <div className="flex flex-wrap gap-1.5">
                                {features.map((feature) => (
                                    <span
                                        key={feature.key}
                                        className={cn(
                                            'rounded-sm border px-1.5 py-0.5 text-2xs',
                                            TONE_PILL[
                                                feature.enabled
                                                    ? 'ok'
                                                    : 'neutral'
                                            ],
                                        )}
                                    >
                                        {featureLabel(feature.key)}
                                        {!feature.enabled && ' · apagado'}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
