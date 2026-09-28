import { Filter } from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

export interface FilterDropdownOption {
    value: string;
    label: string;
}

export interface FilterDropdownProps {
    label: string;
    value: string | null;
    options: FilterDropdownOption[];
    onChange: (value: string | null) => void;
    allLabel?: string;
    className?: string;
}

/**
 * Single-select list filter shared by every roster page (fleet, drivers,
 * events, notifications). Shows "Label: value" while active.
 */
export function FilterDropdown({
    label,
    value,
    options,
    onChange,
    allLabel = 'Todos',
    className,
}: FilterDropdownProps) {
    const active = value !== null;
    const activeLabel = options.find((o) => o.value === value)?.label;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'flex items-center gap-1 rounded-sm border px-2.5 py-1.5 text-2xs whitespace-nowrap transition-colors',
                        active
                            ? 'border-primary/40 bg-primary/10 text-primary'
                            : 'border-border bg-surface-1 text-fg-2 hover:border-border-strong',
                        className,
                    )}
                >
                    <Filter size={11} />
                    {active && activeLabel ? `${label}: ${activeLabel}` : label}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-72 overflow-y-auto"
            >
                <DropdownMenuRadioGroup
                    value={value ?? ''}
                    onValueChange={(v) => onChange(v === '' ? null : v)}
                >
                    <DropdownMenuRadioItem value="">
                        {allLabel}
                    </DropdownMenuRadioItem>
                    {options.length > 0 && <DropdownMenuSeparator />}
                    {options.map((o) => (
                        <DropdownMenuRadioItem key={o.value} value={o.value}>
                            {o.label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
