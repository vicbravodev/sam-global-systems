import { Bell, BellOff, TriangleAlert } from 'lucide-react';
import { ClearFiltersButton, FilterDropdown } from '@/components/sam/list';
import { cn } from '@/lib/utils';
import type {
    NotificationFilterOptions,
    NotificationFilters,
} from '@/types/notifications';

export const EMPTY_NOTIFICATION_FILTERS: NotificationFilters = {
    status: null,
    priority: null,
    unread: false,
    failures: false,
};

export interface NotificationsFilterBarProps {
    filters: NotificationFilters;
    options: NotificationFilterOptions;
    onApply: (next: NotificationFilters) => void;
}

export function NotificationsFilterBar({
    filters,
    options,
    onApply,
}: NotificationsFilterBarProps) {
    const hasActive =
        filters.status !== null ||
        filters.priority !== null ||
        filters.unread ||
        filters.failures;

    return (
        <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border bg-background px-5 py-2">
            <button
                type="button"
                aria-pressed={filters.unread}
                onClick={() => onApply({ ...filters, unread: !filters.unread })}
                className={cn(
                    'flex items-center gap-1 rounded-full border px-2.5 py-1 text-2xs font-medium transition-colors',
                    filters.unread
                        ? 'border-primary/40 bg-primary/10 text-primary'
                        : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong hover:text-fg-1',
                )}
            >
                {filters.unread ? <BellOff size={11} /> : <Bell size={11} />}
                Mis no leídas
            </button>

            <button
                type="button"
                aria-pressed={filters.failures}
                onClick={() =>
                    onApply({ ...filters, failures: !filters.failures })
                }
                className={cn(
                    'flex items-center gap-1 rounded-full border px-2.5 py-1 text-2xs font-medium transition-colors',
                    filters.failures
                        ? 'border-severity-critical/40 bg-severity-critical/10 text-severity-critical'
                        : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong hover:text-fg-1',
                )}
            >
                <TriangleAlert size={11} />
                No entregadas
            </button>

            <FilterDropdown
                label="Estado"
                value={filters.status}
                options={options.statuses}
                allLabel="Todas"
                onChange={(status) => onApply({ ...filters, status })}
            />

            <FilterDropdown
                label="Prioridad"
                value={filters.priority}
                options={options.priorities}
                allLabel="Todas"
                onChange={(priority) => onApply({ ...filters, priority })}
            />

            {hasActive && (
                <ClearFiltersButton
                    onClick={() => onApply(EMPTY_NOTIFICATION_FILTERS)}
                />
            )}
        </div>
    );
}
