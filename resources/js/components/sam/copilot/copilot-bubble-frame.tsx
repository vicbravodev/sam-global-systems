/**
 * The Copilot bubble's dialog shell. Light on purpose: the launcher shows it
 * (with a "connecting" message) while the bubble's chunk is still loading.
 */
export function CopilotBubbleFrame({
    onClose,
    children,
}: {
    onClose: () => void;
    children: React.ReactNode;
}) {
    return (
        <div
            role="dialog"
            aria-label="SAM Copilot"
            // Esc closes the bubble only when focus is inside it and
            // nothing inside (unit picker, stop) already handled it.
            onKeyDown={(e) => {
                if (e.key === 'Escape' && !e.defaultPrevented) {
                    e.preventDefault();
                    onClose();
                }
            }}
            className="fixed inset-x-2 bottom-2 z-50 flex h-[min(640px,calc(100dvh-5rem))] flex-col overflow-hidden rounded-xl border border-border-strong bg-background shadow-xl motion-safe:animate-[sam-copilot-in_var(--motion-normal)_var(--ease-out)_both] sm:inset-x-auto sm:right-5 sm:bottom-5 sm:w-105"
        >
            {children}
        </div>
    );
}

/** Body shown while the bubble connects (chunk or fleet catalog loading). */
export function CopilotBubbleConnecting({
    children = 'Conectando con tu flota…',
}: {
    children?: React.ReactNode;
}) {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center text-xs text-fg-3">
            {children}
        </div>
    );
}
