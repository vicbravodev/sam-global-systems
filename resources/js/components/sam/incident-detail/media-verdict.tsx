import { cn } from '@/lib/utils';
import type { IncidentMediaSummary } from '@/types/sam';

/** Etiquetas en español para el veredicto por media de la IA. */
export const MEDIA_RESULT_LABEL: Record<string, string> = {
    confirms_event: 'Confirma el evento',
    contradicts_event: 'Contradice el evento',
    inconclusive: 'No concluyente',
    low_quality: 'Baja calidad',
    unavailable: 'No disponible',
};

export function mediaResultLabel(result: string | null | undefined): string {
    if (!result) {
        return 'Sin veredicto';
    }

    return MEDIA_RESULT_LABEL[result] ?? result;
}

function Chip({
    tone,
    children,
}: {
    tone: 'critical' | 'ok' | 'neutral' | 'muted';
    children: React.ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-sm border px-1.5 py-0.5 text-2xs font-medium whitespace-nowrap tabular-nums',
                tone === 'critical' &&
                    'border-severity-high/35 bg-severity-high/10 text-severity-high',
                tone === 'ok' &&
                    'border-health-ok/35 bg-health-ok/10 text-health-ok',
                tone === 'neutral' && 'border-border bg-surface-2 text-fg-2',
                tone === 'muted' && 'border-border bg-transparent text-fg-3',
            )}
        >
            {children}
        </span>
    );
}

/**
 * Chips agregados del veredicto visual: cuántas imágenes contradicen,
 * confirman o quedaron sin señal. Es el resumen que conecta la media con la
 * evaluación de la IA.
 */
export function MediaVerdictChips({
    summary,
    className,
}: {
    summary: IncidentMediaSummary;
    className?: string;
}) {
    const unassessed = Math.max(0, summary.total - summary.assessed);
    const noSignal = summary.lowQuality + summary.unavailable;

    return (
        <div className={cn('flex flex-wrap items-center gap-1.5', className)}>
            {summary.contradicts > 0 && (
                <Chip tone="critical">
                    {summary.contradicts}{' '}
                    {summary.contradicts === 1 ? 'contradice' : 'contradicen'}
                </Chip>
            )}
            {summary.confirms > 0 && (
                <Chip tone="ok">
                    {summary.confirms}{' '}
                    {summary.confirms === 1 ? 'confirma' : 'confirman'}
                </Chip>
            )}
            {summary.inconclusive > 0 && (
                <Chip tone="neutral">
                    {summary.inconclusive} no{' '}
                    {summary.inconclusive === 1
                        ? 'concluyente'
                        : 'concluyentes'}
                </Chip>
            )}
            {noSignal > 0 && (
                <Chip tone="muted">{noSignal} sin señal útil</Chip>
            )}
            {unassessed > 0 && (
                <Chip tone="muted">{unassessed} sin evaluar</Chip>
            )}
        </div>
    );
}
