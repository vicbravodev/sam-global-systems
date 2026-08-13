import { cn } from '@/lib/utils';

interface UserAvatarProps {
    initials?: string;
    size?: number;
    isPrimary?: boolean;
    isEmpty?: boolean;
}

export function UserAvatar({
    initials,
    size = 24,
    isPrimary = false,
    isEmpty = false,
}: UserAvatarProps) {
    return (
        <span
            className={cn(
                'inline-grid shrink-0 place-items-center rounded-full border border-border font-semibold',
                isPrimary
                    ? 'bg-primary text-primary-foreground'
                    : isEmpty
                      ? 'bg-surface-3 text-fg-3'
                      : 'bg-surface-3 text-fg-2',
            )}
            style={{
                width: size,
                height: size,
                fontSize: Math.max(9, size * 0.4),
            }}
        >
            {isEmpty ? '?' : initials}
        </span>
    );
}
