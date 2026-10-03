import type { QuickFilter, TenantRow } from './types';

export const NO_PLAN = '__none__';

export function matchesQuick(
    row: TenantRow,
    filter: QuickFilter | null,
): boolean {
    switch (filter) {
        case 'operating':
            return row.stage === 'operating';
        case 'onboarding':
            return row.stage !== 'operating';
        case 'past_due':
            return row.subscriptionStatus === 'past_due';
        case 'suspended':
            return row.subscriptionStatus === 'suspended';
        default:
            return true;
    }
}

export function matchesSearch(row: TenantRow, q: string | null): boolean {
    if (!q) {
        return true;
    }

    const needle = q.toLocaleLowerCase('es');

    return [row.name, row.slug, row.owner?.name, row.owner?.email]
        .filter((v): v is string => Boolean(v))
        .some((v) => v.toLocaleLowerCase('es').includes(needle));
}
