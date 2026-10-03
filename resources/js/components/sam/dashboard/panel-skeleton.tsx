import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Placeholder for a deferred dashboard panel: same card, header and tile grid
 * as `IntegrationsPanel` / `UsagePanel`, so the column does not jump when the
 * data lands.
 */
export function PanelSkeleton({
    title,
    tiles,
}: {
    title: string;
    tiles: number;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0" aria-busy="true">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0">{title}</CardTitle>
                <Skeleton className="h-3 w-28" />
            </CardHeader>
            <CardContent className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-4">
                {Array.from({ length: tiles }, (_, index) => (
                    <Skeleton key={index} className="h-28 rounded-md" />
                ))}
            </CardContent>
        </Card>
    );
}
