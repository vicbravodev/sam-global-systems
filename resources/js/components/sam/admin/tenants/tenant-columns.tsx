import { UserCog } from 'lucide-react';
import {
    StagePill,
    SubscriptionPill,
} from '@/components/sam/admin-tenant-status';
import type { DataTableColumn } from '@/components/sam/data-table/data-table';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDate } from '@/lib/format';
import type { TenantRow } from './types';

/**
 * Columns of the tenants table. `entering` is the tenant whose console is
 * being opened (impersonation in flight): every row's button is disabled
 * meanwhile.
 */
export function tenantColumns({
    entering,
    onImpersonate,
}: {
    entering: number | null;
    onImpersonate: (row: TenantRow) => void;
}): DataTableColumn<TenantRow>[] {
    return [
        {
            key: 'name',
            header: 'Cliente',
            sortValue: (row) => row.name,
            cell: (row) => (
                <span className="flex min-w-0 items-center gap-2.5">
                    <EntityAvatar name={row.name} shape="square" size={26} />
                    <span className="min-w-0">
                        <span className="block truncate font-medium text-fg-1">
                            {row.name}
                        </span>
                        <span className="block truncate font-mono text-3xs text-fg-3">
                            {row.slug}
                        </span>
                    </span>
                </span>
            ),
        },
        {
            key: 'owner',
            header: 'Responsable',
            sortValue: (row) => row.owner?.name ?? null,
            cell: (row) =>
                row.owner ? (
                    <span className="block min-w-0">
                        <span className="block truncate text-fg-1">
                            {row.owner.name}
                        </span>
                        <span className="block truncate text-xs text-fg-3">
                            {row.owner.email}
                        </span>
                    </span>
                ) : (
                    <span className="text-fg-3">Sin responsable</span>
                ),
        },
        {
            key: 'stage',
            header: 'Estado',
            width: 'w-52',
            sortValue: (row) => row.stage,
            cell: (row) => <StagePill stage={row.stage} />,
        },
        {
            key: 'assets',
            header: 'Unidades',
            width: 'w-24',
            align: 'right',
            numeric: true,
            sortValue: (row) => row.monitoredAssets,
            cell: (row) => row.monitoredAssets,
        },
        {
            key: 'subscription',
            header: 'Suscripción',
            width: 'w-40',
            sortValue: (row) => row.subscriptionStatus,
            cell: (row) => (
                <span className="flex flex-col items-start gap-0.5">
                    <SubscriptionPill status={row.subscriptionStatus} />
                    {row.plan ? (
                        <span className="text-3xs text-fg-3">{row.plan}</span>
                    ) : null}
                </span>
            ),
        },
        {
            key: 'created',
            header: 'Alta',
            width: 'w-32',
            sortValue: (row) => row.createdAt,
            cell: (row) => (
                <span className="text-xs whitespace-nowrap text-fg-3 tabular-nums">
                    {formatDate(row.createdAt)}
                </span>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Acciones</span>,
            width: 'w-12',
            align: 'right',
            cell: (row) => (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label={`Entrar a la consola de ${row.name}`}
                            disabled={entering !== null}
                            onClick={(e) => {
                                e.stopPropagation();
                                onImpersonate(row);
                            }}
                        >
                            {entering === row.id ? (
                                <Spinner />
                            ) : (
                                <UserCog className="size-3.5" />
                            )}
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Entrar a su consola</TooltipContent>
                </Tooltip>
            ),
        },
    ];
}
