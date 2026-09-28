import { cn } from '@/lib/utils';
import { providerMonogram } from './integration-state';

interface Props {
    name: string;
    size?: 'sm' | 'md';
    className?: string;
}

/**
 * Square monogram tile standing in for a provider logo: the operator
 * recognises "Sa" for Samsara at a glance without us shipping brand assets.
 */
export function ProviderTile({ name, size = 'md', className }: Props) {
    return (
        <span
            aria-hidden="true"
            title={name}
            className={cn(
                'grid shrink-0 place-items-center rounded-md border border-border bg-surface-2 font-semibold tracking-tight text-fg-1',
                size === 'md' ? 'size-10 text-sm' : 'size-8 text-xs',
                className,
            )}
        >
            {providerMonogram(name)}
        </span>
    );
}
