import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { BillingPanel, money } from './panel';
import type { BillingTerms, FleetCounts, PeriodEstimate } from './types';

/**
 * Unidades vigiladas contra el tope contratado. El tope es suave: pasarse
 * no bloquea, lo de más se cobra como extra al mismo precio por día.
 */
export function CapMeter({
    estimate,
    terms,
    fleet,
    teamSlug,
}: {
    estimate: PeriodEstimate;
    terms: BillingTerms;
    fleet: FleetCounts;
    teamSlug: string | null;
}) {
    const cap = estimate.cap;
    const monitored = fleet.monitored;
    const c = estimate.currency;
    const over = cap !== null && monitored > cap;
    const ratio = cap !== null && cap > 0 ? Math.min(1, monitored / cap) : 0;

    return (
        <BillingPanel
            title="Unidades vigiladas"
            description="Sólo se cobran las unidades que tú decides vigilar."
            bodyClassName="gap-4 px-4 py-4"
        >
            <div className="flex flex-col gap-2">
                <div className="flex items-baseline gap-1.5">
                    <span
                        className={cn(
                            'text-3xl leading-tight font-semibold tracking-tight tabular-nums',
                            over ? 'text-severity-medium' : 'text-fg-1',
                        )}
                    >
                        {formatNumber(monitored)}
                    </span>
                    <span className="text-sm text-fg-3">
                        {cap === null
                            ? 'sin tope contratado'
                            : `de ${formatNumber(cap)} contratadas`}
                    </span>
                </div>
                {cap !== null && (
                    <div
                        className="h-2 overflow-hidden rounded-full bg-surface-3"
                        role="meter"
                        aria-label="Unidades vigiladas contra el tope"
                        aria-valuemin={0}
                        aria-valuemax={cap}
                        aria-valuenow={monitored}
                    >
                        <div
                            className={cn(
                                'h-full rounded-full',
                                over ? 'bg-severity-medium' : 'bg-primary',
                            )}
                            style={{ width: `${Math.max(2, ratio * 100)}%` }}
                        />
                    </div>
                )}
                <p
                    className={cn(
                        'text-xs',
                        over ? 'text-severity-medium' : 'text-fg-3',
                    )}
                >
                    {cap === null
                        ? 'Puedes vigilar todas las unidades que necesites.'
                        : over
                          ? `${formatNumber(monitored - cap)} por encima del tope: se cobran como extra al mismo precio por día.`
                          : `Te quedan ${formatNumber(cap - monitored)} dentro de lo contratado. Pasarte está permitido: lo de más se cobra como extra.`}
                </p>
            </div>

            <dl className="flex flex-col divide-y divide-border rounded-md border border-border text-xs">
                <div className="flex items-center justify-between gap-3 px-3 py-2">
                    <dt className="text-fg-2">Precio por unidad</dt>
                    <dd className="text-right text-fg-1 tabular-nums">
                        {money(estimate.unitPrice, c)} al mes
                        <span className="block text-2xs text-fg-3">
                            {money(estimate.dailyRate, c)} por día vigilada
                        </span>
                    </dd>
                </div>
                {terms.volume_tiers.length > 0 && (
                    <div className="flex flex-col gap-1 px-3 py-2">
                        <dt className="text-fg-2">Precio por volumen</dt>
                        {terms.volume_tiers.map((tier) => (
                            <dd
                                key={tier.from}
                                className="flex justify-between text-fg-3"
                            >
                                <span>
                                    {tier.to === null
                                        ? `${formatNumber(tier.from)} o más unidades`
                                        : `${formatNumber(tier.from)} a ${formatNumber(tier.to)} unidades`}
                                </span>
                                <span className="text-fg-1 tabular-nums">
                                    {money(tier.unit_price, c)}
                                </span>
                            </dd>
                        ))}
                    </div>
                )}
                {terms.min_billable_assets > 0 && (
                    <div className="flex items-center justify-between gap-3 px-3 py-2">
                        <dt className="text-fg-2">Mínimo al mes</dt>
                        <dd className="text-fg-1 tabular-nums">
                            {formatNumber(terms.min_billable_assets)} unidades
                        </dd>
                    </div>
                )}
                <FleetRow
                    label="Sin vigilar, esperando tu decisión"
                    value={fleet.pending}
                    href={
                        teamSlug && fleet.pending > 0
                            ? `/${teamSlug}/assets?monitoring=pending`
                            : null
                    }
                    highlight={fleet.pending > 0}
                />
                <FleetRow
                    label="Excluidas (no se vigilan ni se cobran)"
                    value={fleet.excluded}
                    href={
                        teamSlug && fleet.excluded > 0
                            ? `/${teamSlug}/assets?monitoring=excluded`
                            : null
                    }
                />
            </dl>
        </BillingPanel>
    );
}

function FleetRow({
    label,
    value,
    href,
    highlight = false,
}: {
    label: string;
    value: number;
    href: string | null;
    highlight?: boolean;
}) {
    const content = (
        <>
            <dt className="text-fg-2">{label}</dt>
            <dd className="flex items-center gap-1">
                <span
                    className={cn(
                        'tabular-nums',
                        highlight
                            ? 'font-semibold text-severity-medium'
                            : 'text-fg-1',
                    )}
                >
                    {formatNumber(value)}
                </span>
                {href && (
                    <ChevronRight
                        className="size-3.5 text-fg-3"
                        aria-hidden="true"
                    />
                )}
            </dd>
        </>
    );

    if (href) {
        return (
            <Link
                href={href}
                className="flex items-center justify-between gap-3 px-3 py-2 hover:bg-surface-2"
            >
                {content}
            </Link>
        );
    }

    return (
        <div className="flex items-center justify-between gap-3 px-3 py-2">
            {content}
        </div>
    );
}
