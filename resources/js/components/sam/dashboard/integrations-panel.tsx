import { ProviderTag } from '@/components/sam';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DashboardIntegration } from '@/types/dashboard';

export function IntegrationsPanel({
    integrations,
}: {
    integrations: DashboardIntegration[];
}) {
    const HEALTH_DOT: Record<DashboardIntegration['health'], string> = {
        ok: 'bg-health-ok',
        warn: 'bg-health-warn',
        down: 'bg-health-down',
        unknown: 'bg-health-unknown',
    };
    const alertCount = integrations.filter((i) => i.health !== 'ok').length;

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Integraciones</CardTitle>
                <span className="sam-meta">
                    {integrations.length} proveedores · {alertCount} con alertas
                </span>
            </CardHeader>
            <CardContent className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-4">
                {integrations.length === 0 ? (
                    <p className="px-1 py-3 text-sm text-fg-3 sm:col-span-2 xl:col-span-4">
                        Sin integraciones conectadas todavía.
                    </p>
                ) : (
                    integrations.map((integration) => (
                        <div
                            key={integration.id}
                            className="rounded-md border border-border bg-surface-2 p-3"
                        >
                            <div className="mb-2 flex items-center gap-2">
                                <ProviderTag name={integration.provider} />
                                <span className="flex-1 truncate text-sm font-semibold">
                                    {integration.name}
                                </span>
                                <span
                                    className={cn(
                                        'size-2 rounded-full',
                                        HEALTH_DOT[integration.health],
                                    )}
                                    aria-label={`Estado: ${integration.health}`}
                                />
                            </div>
                            <div className="font-mono text-xl tabular-nums">
                                {formatNumber(integration.events24h)}
                            </div>
                            <div className="sam-meta">eventos · últ. 24 h</div>
                            <div className="mt-2 font-mono text-3xs text-fg-3">
                                sync: {integration.lastSync ?? '—'}
                            </div>
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}
