import { Bars } from '@/components/sam/charts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatShortDate } from '@/lib/format';
import type { CopilotUsageReport } from '@/types/copilot';

export function DailyQueriesCard({ usage }: { usage: CopilotUsageReport }) {
    const t = usage.totals;
    const labels = usage.series.map((point, index) =>
        index % Math.ceil(usage.series.length / 10) === 0
            ? formatShortDate(point.date)
            : '',
    );

    return (
        <Card className="gap-0 py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">Consultas por día</CardTitle>
            </CardHeader>
            <CardContent className="px-4 py-3">
                {t.queries === 0 ? (
                    <p className="py-10 text-center text-sm text-fg-3">
                        Sin consultas en el periodo.
                    </p>
                ) : (
                    <Bars
                        data={usage.series.map((p) => p.queries)}
                        labels={labels}
                        height={200}
                        width={720}
                        color="var(--ai-accent)"
                        valueFmt={(v) => `${v} consultas`}
                        aria-label="Consultas por día"
                    />
                )}
            </CardContent>
        </Card>
    );
}
