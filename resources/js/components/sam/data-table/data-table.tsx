import { ChevronDown, ChevronUp } from 'lucide-react';
import * as React from 'react';
import { EmptyState } from '@/components/ui/empty-state';
import { Pagination } from '@/components/ui/pagination';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

/**
 * Tabla reutilizable de la consola (patrón único para todas las listas):
 * sorting client-side, header sticky, densidad por tokens --row-*,
 * selección con checkbox, skeleton con la forma real de las filas, empty
 * state integrado y paginación client-side opcional. Sin TanStack:
 * column-defs propias sobre el patrón ya probado de assets/inbox.
 */

export type DataTableDensity = 'compact' | 'comfortable' | 'relaxed';

export interface DataTableColumn<T> {
    key: string;
    header: React.ReactNode;
    cell: (row: T) => React.ReactNode;
    /** Habilita sorting por esta columna. */
    sortValue?: (row: T) => string | number | null;
    /** Clase de ancho Tailwind (p.ej. "w-32"). Sin ella la columna fluye. */
    width?: string;
    align?: 'left' | 'right';
    /** Números tabulares en mono (contadores, importes). */
    numeric?: boolean;
}

interface DataTableProps<T> {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    onRowClick?: (row: T) => void;
    density?: DataTableDensity;
    /** Selección por checkbox (controlada). */
    selected?: ReadonlySet<string | number>;
    onToggleSelect?: (key: string | number) => void;
    onToggleAll?: () => void;
    /** Estado de carga: pinta skeleton con la forma de las filas. */
    loading?: boolean;
    skeletonRows?: number;
    /** Empty state (usar <EmptyState> con CTA); hay uno genérico por defecto. */
    empty?: React.ReactNode;
    defaultSort?: { key: string; dir: 'asc' | 'desc' };
    /** Paginación client-side opcional. */
    pageSize?: number;
    className?: string;
}

const DENSITY_H: Record<DataTableDensity, string> = {
    compact: 'h-(--row-compact)',
    comfortable: 'h-(--row-comfortable)',
    relaxed: 'h-(--row-relaxed)',
};

function compareValues(
    a: string | number | null,
    b: string | number | null,
): number {
    if (a === null && b === null) {
        return 0;
    }

    if (a === null) {
        return 1;
    }

    if (b === null) {
        return -1;
    }

    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    return String(a).localeCompare(String(b), 'es', { sensitivity: 'base' });
}

export function DataTable<T>({
    columns,
    rows,
    rowKey,
    onRowClick,
    density = 'comfortable',
    selected,
    onToggleSelect,
    onToggleAll,
    loading = false,
    skeletonRows = 8,
    empty,
    defaultSort,
    pageSize,
    className,
}: DataTableProps<T>) {
    const [sort, setSort] = React.useState<{
        key: string;
        dir: 'asc' | 'desc';
    } | null>(defaultSort ?? null);
    const [page, setPage] = React.useState(1);

    const selectable = selected !== undefined && onToggleSelect !== undefined;
    const rowH = DENSITY_H[density];

    const sorted = React.useMemo(() => {
        if (sort === null) {
            return rows;
        }

        const column = columns.find((col) => col.key === sort.key);

        if (!column?.sortValue) {
            return rows;
        }

        const factor = sort.dir === 'asc' ? 1 : -1;

        return [...rows].sort(
            (a, b) =>
                compareValues(column.sortValue!(a), column.sortValue!(b)) *
                factor,
        );
    }, [rows, sort, columns]);

    const totalPages =
        pageSize !== undefined ? Math.ceil(sorted.length / pageSize) : 1;
    const currentPage = Math.min(page, Math.max(1, totalPages));
    const visible =
        pageSize !== undefined
            ? sorted.slice((currentPage - 1) * pageSize, currentPage * pageSize)
            : sorted;

    const toggleSort = (key: string) => {
        setSort((prev) => {
            if (prev?.key !== key) {
                return { key, dir: 'asc' };
            }

            return prev.dir === 'asc' ? { key, dir: 'desc' } : null;
        });
    };

    const allChecked =
        selectable &&
        visible.length > 0 &&
        visible.every((row) => selected.has(rowKey(row)));

    if (!loading && rows.length === 0) {
        return (
            <>
                {empty ?? (
                    <EmptyState
                        title="Sin resultados"
                        description="No hay elementos que coincidan con los filtros actuales."
                    />
                )}
            </>
        );
    }

    return (
        <div className={cn('min-h-0 flex-1 overflow-auto', className)}>
            {/* min-w: en viewports angostos la tabla scrollea dentro del
                wrapper en vez de aplastar las columnas. */}
            <table className="w-full min-w-[640px] border-collapse">
                <thead>
                    <tr className="sticky top-0 z-10 border-b border-border bg-surface-3 text-[10px] font-semibold tracking-[0.08em] text-fg-3 uppercase">
                        {selectable && (
                            <th className="w-[34px] px-2.5 py-2 text-left">
                                <input
                                    type="checkbox"
                                    checked={allChecked}
                                    onChange={() => onToggleAll?.()}
                                    aria-label="Seleccionar todo"
                                    className="size-3.5 align-middle accent-primary"
                                />
                            </th>
                        )}
                        {columns.map((column) => {
                            const sortable = column.sortValue !== undefined;
                            const active = sort?.key === column.key;

                            return (
                                <th
                                    key={column.key}
                                    className={cn(
                                        'px-2.5 py-2',
                                        column.width,
                                        column.align === 'right'
                                            ? 'text-right'
                                            : 'text-left',
                                    )}
                                    aria-sort={
                                        active
                                            ? sort?.dir === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    }
                                >
                                    {sortable ? (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                toggleSort(column.key)
                                            }
                                            className={cn(
                                                'inline-flex items-center gap-0.5 uppercase transition-colors hover:text-fg-1',
                                                active && 'text-fg-1',
                                            )}
                                        >
                                            {column.header}
                                            {active &&
                                                (sort?.dir === 'asc' ? (
                                                    <ChevronUp className="size-3" />
                                                ) : (
                                                    <ChevronDown className="size-3" />
                                                ))}
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody>
                    {loading
                        ? Array.from({ length: skeletonRows }).map((_, i) => (
                              <tr
                                  key={`skeleton-${i}`}
                                  className={cn('border-b border-border', rowH)}
                              >
                                  {selectable && (
                                      <td className="px-2.5">
                                          <Skeleton className="size-3.5" />
                                      </td>
                                  )}
                                  {columns.map((column) => (
                                      <td
                                          key={column.key}
                                          className={cn('px-2.5', column.width)}
                                      >
                                          <Skeleton className="h-3.5 w-3/4" />
                                      </td>
                                  ))}
                              </tr>
                          ))
                        : visible.map((row) => {
                              const key = rowKey(row);

                              return (
                                  <tr
                                      key={key}
                                      onClick={
                                          onRowClick
                                              ? () => onRowClick(row)
                                              : undefined
                                      }
                                      onKeyDown={
                                          onRowClick
                                              ? (e) => {
                                                    if (e.key === 'Enter') {
                                                        onRowClick(row);
                                                    }
                                                }
                                              : undefined
                                      }
                                      tabIndex={onRowClick ? 0 : undefined}
                                      className={cn(
                                          'border-b border-border transition-colors',
                                          rowH,
                                          onRowClick &&
                                              'cursor-pointer outline-none hover:bg-surface-2 focus-visible:bg-surface-2',
                                          selectable &&
                                              selected.has(key) &&
                                              'bg-primary/[8%]',
                                      )}
                                  >
                                      {selectable && (
                                          <td
                                              className="px-2.5"
                                              onClick={(e) => {
                                                  e.stopPropagation();
                                                  onToggleSelect(key);
                                              }}
                                          >
                                              <input
                                                  type="checkbox"
                                                  checked={selected.has(key)}
                                                  onChange={() =>
                                                      onToggleSelect(key)
                                                  }
                                                  aria-label="Seleccionar fila"
                                                  className="size-3.5 align-middle accent-primary"
                                              />
                                          </td>
                                      )}
                                      {columns.map((column) => (
                                          <td
                                              key={column.key}
                                              className={cn(
                                                  'px-2.5',
                                                  column.width,
                                                  column.align === 'right' &&
                                                      'text-right',
                                                  column.numeric &&
                                                      'font-mono text-[12px] tabular-nums',
                                              )}
                                          >
                                              {column.cell(row)}
                                          </td>
                                      ))}
                                  </tr>
                              );
                          })}
                </tbody>
            </table>

            {pageSize !== undefined && totalPages > 1 && (
                <Pagination
                    className="border-t border-border py-2"
                    page={currentPage}
                    totalPages={totalPages}
                    onPageChange={setPage}
                />
            )}
        </div>
    );
}
