import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ChoiceGroup } from '@/components/sam/analytics/choice-group';
import { IndicatorsTab } from '@/components/sam/analytics/indicators-tab';
import { ReportsTab } from '@/components/sam/analytics/reports-tab';
import type { AnalyticsPageProps } from '@/components/sam/analytics/types';
import { TabBar } from '@/components/sam/tab-bar';
import { PageHeader } from '@/components/ui/page-header';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';

type TabKey = 'indicators' | 'reports';

export default function AnalyticsIndex() {
    const page = usePage();
    const props = page.props as unknown as AnalyticsPageProps;
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const [tab, setTab] = useState<TabKey>('indicators');
    const [loading, setLoading] = useState(false);

    const periods = props.periods ?? [7, 30, 90];

    const changePeriod = (days: number) => {
        if (days === props.period) {
            return;
        }

        router.reload({
            data: { period: days },
            only: ['metrics', 'period', 'range'],
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    const running = props.executions.filter(
        (execution) =>
            execution.status === 'running' || execution.status === 'pending',
    ).length;

    return (
        <>
            <Head title="Analítica" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <PageHeader
                    title="Analítica"
                    meta={
                        tab === 'indicators' ? (
                            <span className="text-xs text-fg-3">
                                {formatDate(props.range.from)} –{' '}
                                {formatDate(props.range.to)}
                            </span>
                        ) : running > 0 ? (
                            <span className="text-xs text-severity-info">
                                {running}{' '}
                                {running === 1
                                    ? 'reporte en preparación'
                                    : 'reportes en preparación'}
                            </span>
                        ) : undefined
                    }
                    actions={
                        tab === 'indicators' ? (
                            <ChoiceGroup
                                aria-label="Periodo a analizar"
                                options={periods.map((days) => ({
                                    value: days,
                                    label: `${days} días`,
                                    hint: `Últimos ${days} días, comparados con los ${days} anteriores`,
                                }))}
                                value={props.period}
                                onChange={changePeriod}
                            />
                        ) : undefined
                    }
                    className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
                />

                <TabBar
                    aria-label="Secciones de analítica"
                    items={[
                        { key: 'indicators', label: 'Indicadores' },
                        {
                            key: 'reports',
                            label: 'Reportes',
                            count: props.reports.length,
                        },
                    ]}
                    value={tab}
                    onChange={(key) => setTab(key as TabKey)}
                    className="shrink-0 px-5"
                />

                <div
                    key={tab}
                    className={cn(
                        'min-h-0 flex-1 overflow-y-auto transition-opacity',
                        loading && 'opacity-60',
                    )}
                    aria-busy={loading}
                >
                    <div className="mx-auto flex w-full max-w-7xl flex-col p-5">
                        {tab === 'indicators' ? (
                            <IndicatorsTab
                                metrics={props.metrics}
                                fleet={props.fleet}
                                period={props.period}
                                longestPeriod={periods[periods.length - 1]}
                                onPeriod={changePeriod}
                                hasReports={props.reports.length > 0}
                                onShowReports={() => setTab('reports')}
                            />
                        ) : (
                            <ReportsTab
                                reports={props.reports}
                                executions={props.executions}
                                formats={props.formats}
                                canGenerate={props.canGenerate}
                                teamSlug={teamSlug}
                            />
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

AnalyticsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Analítica',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/analytics`
                : '/analytics',
        },
    ],
});
