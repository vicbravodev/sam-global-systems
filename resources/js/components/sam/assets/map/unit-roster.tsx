import { Search } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { relativeLabel } from '@/lib/time';
import type { AssetMarker } from '@/types/assets';
import { RosterRow } from './roster-row';

export interface UnitRosterProps {
    /** Units after the filters, most urgent first. */
    listed: AssetMarker[];
    /** Whether the fleet has any positioned unit at all. */
    hasMarkers: boolean;
    selectedId: number | null;
    statusLabels: Record<string, string>;
    query: string;
    onQueryChange: (query: string) => void;
    filtering: boolean;
    onClearFilters: () => void;
    onPick: (id: number) => void;
    statusChips: ReactNode;
}

/** Unit roster: search, status filter, most urgent first. */
export function UnitRoster({
    listed,
    hasMarkers,
    selectedId,
    statusLabels,
    query,
    onQueryChange,
    filtering,
    onClearFilters,
    onPick,
    statusChips,
}: UnitRosterProps) {
    return (
        <aside className="hidden w-80 shrink-0 flex-col border-r border-border bg-surface-1 lg:flex">
            <div className="flex flex-col gap-2.5 border-b border-border p-3">
                <label className="relative block">
                    <span className="sr-only">Buscar unidad</span>
                    <Search
                        className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-fg-3"
                        aria-hidden="true"
                    />
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => onQueryChange(e.target.value)}
                        placeholder="Unidad, código o conductor"
                        className="h-8 w-full rounded-md border border-border bg-background pr-2.5 pl-8 text-xs text-fg-1 transition-colors outline-none placeholder:text-fg-3 hover:border-border-strong focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                    />
                </label>
                {statusChips}
            </div>

            <ul
                className="min-h-0 flex-1 overflow-y-auto py-1"
                aria-label="Unidades en el mapa"
            >
                {listed.map((asset) => (
                    <RosterRow
                        key={asset.id}
                        asset={asset}
                        selected={asset.id === selectedId}
                        statusLabel={statusLabels[asset.status] || asset.status}
                        seenLabel={relativeLabel(asset.recordedAt)}
                        onPick={onPick}
                    />
                ))}
            </ul>

            {listed.length === 0 && hasMarkers && (
                <div className="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center">
                    <p className="text-xs text-fg-3">
                        Ninguna unidad coincide con los filtros.
                    </p>
                    {filtering && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={onClearFilters}
                        >
                            Quitar filtros
                        </Button>
                    )}
                </div>
            )}
        </aside>
    );
}
