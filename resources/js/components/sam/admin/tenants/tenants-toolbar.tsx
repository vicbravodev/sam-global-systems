import {
    AlertTriangle,
    CircleCheck,
    Hourglass,
    PauseCircle,
} from 'lucide-react';
import { ClearFiltersButton, SearchInput } from '@/components/sam/list';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import type { QuickFilter, TenantStats } from './types';

export interface TenantsToolbarProps {
    stats: TenantStats;
    quick: QuickFilter | null;
    onToggleQuick: (value: QuickFilter) => void;
    search: string | null;
    onSearch: (q: string | null) => void;
    filtered: boolean;
    onClearFilters: () => void;
    /** Rows left after the filters. */
    shown: number;
}

/** Quick filters (pulse) and client-side search over the tenants list. */
export function TenantsToolbar({
    stats,
    quick,
    onToggleQuick,
    search,
    onSearch,
    filtered,
    onClearFilters,
    shown,
}: TenantsToolbarProps) {
    const toggle = (value: QuickFilter) => () => onToggleQuick(value);

    return (
        <>
            <PulseStrip>
                <PulseStat
                    label="Operando"
                    value={stats.operating}
                    tone="ok"
                    icon={CircleCheck}
                    onClick={toggle('operating')}
                    active={quick === 'operating'}
                />
                <PulseStat
                    label="En alta"
                    value={stats.onboarding}
                    hint="Por terminar"
                    tone={stats.onboarding > 0 ? 'warn' : 'neutral'}
                    icon={Hourglass}
                    onClick={toggle('onboarding')}
                    active={quick === 'onboarding'}
                />
                <PulseStat
                    label="Pago vencido"
                    value={stats.pastDue}
                    tone={stats.pastDue > 0 ? 'critical' : 'neutral'}
                    icon={AlertTriangle}
                    onClick={toggle('past_due')}
                    active={quick === 'past_due'}
                />
                <PulseStat
                    label="Suspendidos"
                    value={stats.suspended}
                    icon={PauseCircle}
                    onClick={toggle('suspended')}
                    active={quick === 'suspended'}
                />
            </PulseStrip>

            <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
                <SearchInput
                    value={search}
                    onApply={onSearch}
                    placeholder="Buscar cliente o responsable…"
                    delay={150}
                    className="w-full sm:w-80"
                />
                {filtered ? (
                    <ClearFiltersButton onClick={onClearFilters} />
                ) : null}
                <span className="ml-auto text-xs text-fg-3 tabular-nums">
                    {shown} de {stats.total}
                </span>
            </div>
        </>
    );
}
