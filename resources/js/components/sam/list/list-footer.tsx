import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';

export interface ListPagination {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

interface Props {
    pagination: ListPagination;
    /** Rows on the current page. */
    shown: number;
    onPage: (page: number) => void;
    /** [singular, plural] noun, e.g. ['conductor', 'conductores']. */
    noun: [string, string];
}

/** Range + prev/next footer shared by every server-paginated list. */
export function ListFooter({ pagination, shown, onPage, noun }: Props) {
    const from =
        shown === 0 ? 0 : (pagination.page - 1) * pagination.perPage + 1;
    const to = (pagination.page - 1) * pagination.perPage + shown;

    return (
        <div className="flex shrink-0 items-center justify-between border-t border-border bg-surface-1 px-5 py-2">
            <span className="text-2xs text-fg-3">
                {from}–{to} de {pagination.total}{' '}
                {pagination.total === 1 ? noun[0] : noun[1]}
                {pagination.lastPage > 1 && (
                    <span className="ml-2 font-mono tabular-nums">
                        · pág. {pagination.page}/{pagination.lastPage}
                    </span>
                )}
            </span>
            <div className="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={pagination.page <= 1}
                    onClick={() => onPage(pagination.page - 1)}
                >
                    <ChevronLeft size={13} />
                    Anterior
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={pagination.page >= pagination.lastPage}
                    onClick={() => onPage(pagination.page + 1)}
                >
                    Siguiente
                    <ChevronRight size={13} />
                </Button>
            </div>
        </div>
    );
}
