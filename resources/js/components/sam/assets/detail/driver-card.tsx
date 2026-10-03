import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { AssetShowProps } from '@/types/assets';

export function DriverCard({
    driver,
    teamSlug,
}: {
    driver: AssetShowProps['asset']['driver'];
    teamSlug: string | null;
}) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <User size={15} /> Conductor
                </CardTitle>
            </CardHeader>
            <CardContent className="p-4">
                {driver === null ? (
                    <p className="text-sm text-fg-3">
                        Sin conductor asignado ahora mismo. La asignación llega
                        del proveedor (Samsara) o se registra a mano.
                    </p>
                ) : (
                    <Link
                        href={
                            teamSlug ? `/${teamSlug}/drivers/${driver.id}` : '#'
                        }
                        className="flex items-center gap-3 rounded-md border border-border bg-surface-2 p-3 transition-colors hover:border-primary/40"
                    >
                        <EntityAvatar name={driver.name} size={40} />
                        <span className="flex min-w-0 flex-col">
                            <span className="truncate text-sm font-medium text-fg-1">
                                {driver.name}
                            </span>
                            <span className="font-mono text-2xs text-fg-3">
                                {driver.employeeCode ?? 'conductor principal'}
                            </span>
                        </span>
                    </Link>
                )}
            </CardContent>
        </Card>
    );
}
