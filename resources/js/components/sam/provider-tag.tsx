import { cn } from '@/lib/utils';

interface Props {
    name: string;
    className?: string;
}

export function ProviderTag({ name, className }: Props) {
    const label = (name || '?').slice(0, 7).toUpperCase();

    return (
        <span
            className={cn(
                'inline-flex rounded-sm border border-border bg-surface-2 px-1.5 py-[3px] font-mono text-3xs tracking-caps text-fg-3',
                className,
            )}
            title={name}
        >
            {label}
        </span>
    );
}
