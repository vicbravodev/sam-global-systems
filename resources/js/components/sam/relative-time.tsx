import { ageLabel } from '@/lib/time';
import { cn } from '@/lib/utils';

interface Props {
    /** Age in minutes. */
    minutes: number;
    className?: string;
}

export function RelativeTime({ minutes, className }: Props) {
    return (
        <span
            className={cn(
                'font-mono text-2xs whitespace-nowrap text-fg-3 tabular-nums',
                className,
            )}
        >
            {ageLabel(minutes)}
        </span>
    );
}
