import { cn } from '@/lib/utils';

interface Props {
    /** Age in minutes. */
    minutes: number;
    className?: string;
}

export function RelativeTime({ minutes, className }: Props) {
    const text =
        minutes < 1
            ? 'ahora'
            : minutes < 60
              ? `hace ${minutes} min`
              : minutes < 1440
                ? `hace ${Math.floor(minutes / 60)} h`
                : `hace ${Math.floor(minutes / 1440)} d`;

    return (
        <span
            className={cn(
                'font-mono text-[11px] text-fg-3 tabular-nums',
                className,
            )}
        >
            {text}
        </span>
    );
}
