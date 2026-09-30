import { router, usePage } from '@inertiajs/react';
import { Maximize2, MessageSquarePlus, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { CopilotCatalog, CopilotQuota } from '@/types/copilot';
import { CopilotChatPanel } from './copilot-chat-panel';
import { useCopilotChat } from './use-copilot-chat';

/**
 * "Pregúntale a SAM": floating Copilot bubble available on every ops page
 * (⌘J / Ctrl+J). Hidden on the full Copilot page and for users whose role
 * or tenant plan doesn't include the module.
 */
export function CopilotLauncher() {
    const page = usePage();
    const team = page.props.currentTeam;
    const teamSlug = team?.slug ?? '';
    const onCopilotPage = page.url.split(/[?#]/)[0] === `/${teamSlug}/copilot`;
    const enabled =
        Boolean(page.props.copilot?.enabled) &&
        !onCopilotPage &&
        teamSlug !== '';

    if (!enabled) {
        return null;
    }

    // Keyed by tenant: switching team remounts the bubble, so the thread and
    // the fleet catalog of the previous tenant can never leak into the next.
    return <LauncherForTeam key={teamSlug} teamSlug={teamSlug} />;
}

type CatalogState =
    | { status: 'idle' | 'loading' | 'failed' }
    | { status: 'ready'; data: CopilotCatalog };

function LauncherForTeam({ teamSlug }: { teamSlug: string }) {
    const page = usePage();
    const team = page.props.currentTeam;
    const user = page.props.auth?.user;

    const [open, setOpen] = useState(false);
    const launcherRef = useRef<HTMLButtonElement | null>(null);
    /** Where focus was when the bubble opened: it goes back there on close. */
    const returnFocusRef = useRef<HTMLElement | null>(null);
    const [catalog, setCatalog] = useState<CatalogState>({ status: 'idle' });

    const chat = useCopilotChat({
        teamSlug,
        channel: 'bubble',
        onQuota: useCallback(
            (quota: CopilotQuota) =>
                setCatalog((current) =>
                    current.status === 'ready'
                        ? { ...current, data: { ...current.data, quota } }
                        : current,
                ),
            [],
        ),
    });

    const loadCatalog = useCallback(() => {
        setCatalog({ status: 'loading' });
        fetch(`/${teamSlug}/copilot/catalog`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(),
            )
            .then((data: CopilotCatalog) =>
                setCatalog({ status: 'ready', data }),
            )
            .catch(() => setCatalog({ status: 'failed' }));
    }, [teamSlug]);

    const show = useCallback(() => {
        const current = document.activeElement;
        returnFocusRef.current =
            current instanceof HTMLElement && current !== document.body
                ? current
                : null;
        setOpen(true);

        if (catalog.status === 'idle' || catalog.status === 'failed') {
            loadCatalog();
        }
    }, [catalog.status, loadCatalog]);

    const close = useCallback(() => setOpen(false), []);

    // Focus goes back to where it was (or the launcher) once the bubble is
    // gone; the launcher stays mounted so it is always a valid target.
    const wasOpen = useRef(false);

    useEffect(() => {
        if (open) {
            wasOpen.current = true;

            return;
        }

        if (wasOpen.current) {
            wasOpen.current = false;
            const target = returnFocusRef.current;
            (target?.isConnected ? target : launcherRef.current)?.focus();
            returnFocusRef.current = null;
        }
    }, [open]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'j') {
                e.preventDefault();

                if (open) {
                    close();
                } else {
                    show();
                }
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [close, open, show]);

    const openFull = () => {
        returnFocusRef.current = null;
        const suffix = chat.conversationId ? `?c=${chat.conversationId}` : '';
        setOpen(false);
        router.visit(`/${teamSlug}/copilot${suffix}`);
    };

    return (
        <>
            {/* Burbuja compacta y por encima de la franja inferior: las
                páginas ponen ahí pies de tabla, paginación, atajos de teclado
                y la atribución del mapa, y una píldora ancha en la esquina
                los tapaba. */}
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        ref={launcherRef}
                        type="button"
                        hidden={open}
                        onClick={show}
                        className="fixed right-4 bottom-16 z-40 grid size-11 cursor-pointer place-items-center rounded-full bg-ai-accent text-white shadow-lg transition-[transform,filter] duration-(--motion-fast) hover:brightness-110 active:scale-[0.97] motion-safe:animate-[sam-copilot-in_var(--motion-slow)_var(--ease-out)_both]"
                        aria-label="Pregúntale a SAM (Ctrl+J)"
                    >
                        <Sparkles className="size-5" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="left">
                    Pregúntale a SAM
                    <kbd className="ml-1.5 font-mono text-3xs opacity-70">
                        ⌘J
                    </kbd>
                </TooltipContent>
            </Tooltip>

            {open && (
                <div
                    role="dialog"
                    aria-label="SAM Copilot"
                    // Esc closes the bubble only when focus is inside it and
                    // nothing inside (unit picker, stop) already handled it.
                    onKeyDown={(e) => {
                        if (e.key === 'Escape' && !e.defaultPrevented) {
                            e.preventDefault();
                            close();
                        }
                    }}
                    className="fixed inset-x-2 bottom-2 z-50 flex h-[min(640px,calc(100dvh-5rem))] flex-col overflow-hidden rounded-xl border border-border-strong bg-background shadow-xl motion-safe:animate-[sam-copilot-in_var(--motion-normal)_var(--ease-out)_both] sm:inset-x-auto sm:right-5 sm:bottom-5 sm:w-105"
                >
                    <div className="flex shrink-0 items-center gap-2 border-b border-border bg-surface-1 px-3 py-2.5">
                        <div className="grid size-7 place-items-center rounded-md bg-ai-accent-bg text-ai-accent">
                            <Sparkles className="size-3.5" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="text-sm font-semibold text-fg-1">
                                SAM Copilot
                            </div>
                            <div className="truncate font-mono text-3xs text-fg-3">
                                {team?.name}
                            </div>
                        </div>
                        <HeadButton
                            label="Nueva conversación"
                            onClick={chat.reset}
                        >
                            <MessageSquarePlus className="size-3.5" />
                        </HeadButton>
                        <HeadButton
                            label="Abrir vista completa"
                            onClick={openFull}
                        >
                            <Maximize2 className="size-3.5" />
                        </HeadButton>
                        <HeadButton label="Cerrar (Esc)" onClick={close}>
                            <X className="size-3.5" />
                        </HeadButton>
                    </div>
                    {catalog.status === 'ready' ? (
                        <CopilotChatPanel
                            chat={chat}
                            catalog={catalog.data}
                            userName={user?.name ?? ''}
                            teamName={team?.name ?? ''}
                            compact
                        />
                    ) : (
                        <div className="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center text-xs text-fg-3">
                            {catalog.status === 'failed' ? (
                                <>
                                    No pude conectar con SAM Copilot.
                                    <button
                                        type="button"
                                        onClick={loadCatalog}
                                        className="cursor-pointer rounded-md border border-border bg-surface-2 px-3 py-1.5 text-xs font-medium text-fg-1 transition-transform duration-(--motion-fast) ease-(--ease-out) hover:bg-surface-3 active:scale-97"
                                    >
                                        Reintentar
                                    </button>
                                </>
                            ) : (
                                'Conectando con tu flota…'
                            )}
                        </div>
                    )}
                </div>
            )}
        </>
    );
}

function HeadButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    onClick={onClick}
                    className="grid size-7 cursor-pointer place-items-center rounded-md text-fg-3 hover:bg-surface-2 hover:text-fg-1"
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
