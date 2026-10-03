import type { SharedPageProps } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { DailyQueriesCard } from '@/components/sam/copilot/usage/daily-queries-card';
import { QuotaCard } from '@/components/sam/copilot/usage/quota-card';
import { RecentQueriesCard } from '@/components/sam/copilot/usage/recent-queries-card';
import { UsageByUserCard } from '@/components/sam/copilot/usage/usage-by-user-card';
import { UsageMetrics } from '@/components/sam/copilot/usage/usage-metrics';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/utils';
import copilotRoutes from '@/routes/copilot';
import type { CopilotUsageReport } from '@/types/copilot';

const RANGES = [7, 30, 90] as const;

export default function CopilotUsage({ usage }: { usage: CopilotUsageReport }) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';

    const setRange = (days: number) =>
        router.get(
            copilotRoutes.usage.url(teamSlug),
            { days },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <>
            <Head title="Uso de SAM Copilot" />
            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
                <PageHeader
                    title="Uso de SAM Copilot"
                    description="Consultas, tokens consumidos, costo estimado y quién usa el asistente en tu empresa."
                    actions={
                        <>
                            <div className="flex gap-0.5 rounded-md border border-border bg-surface-2 p-0.5">
                                {RANGES.map((days) => (
                                    <button
                                        key={days}
                                        type="button"
                                        onClick={() => setRange(days)}
                                        className={cn(
                                            'cursor-pointer rounded-sm px-2.5 py-1 text-xs font-medium',
                                            usage.range.days === days
                                                ? 'bg-surface-1 text-fg-1 shadow-xs'
                                                : 'text-fg-3 hover:text-fg-1',
                                        )}
                                    >
                                        {days} días
                                    </button>
                                ))}
                            </div>
                            <Link
                                href={copilotRoutes.index(teamSlug)}
                                className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm text-fg-2 hover:bg-surface-2"
                            >
                                <ArrowLeft className="size-4" /> Volver al chat
                            </Link>
                        </>
                    }
                />
                <UsageMetrics totals={usage.totals} />

                <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <DailyQueriesCard usage={usage} />
                    <QuotaCard usage={usage} />
                </div>

                <UsageByUserCard rows={usage.byUser} />

                <RecentQueriesCard rows={usage.recent} />
            </div>
        </>
    );
}

CopilotUsage.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'SAM Copilot',
            href: props.currentTeam
                ? copilotRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
        {
            title: 'Uso',
            href: props.currentTeam
                ? copilotRoutes.usage.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
