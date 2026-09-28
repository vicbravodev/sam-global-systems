import { router, usePage } from '@inertiajs/react';
import { Maximize2, MessageSquarePlus, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
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
        setOpen(true);

        if (catalog.status === 'idle' || catalog.status === 'failed') {
            loadCatalog();
        }
    }, [catalog.status, loadCatalog]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'j') {
                e.preventDefault();

                if (open) {
                    setOpen(false);
                } else {
                    show();
                }
            }

            if (e.key === 'Escape') {
                setOpen(false);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [open, show]);

    const openFull = () => {
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
            {!open && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <button
                            type="button"
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
            )}

            {open && (
                <div
                    role="dialog"
                    aria-label="SAM Copilot"
                    className="fixed inset-x-2 bottom-2 z-50 flex h-[min(640px,calc(100dvh-5rem))] flex-col overflow-hidden rounded-xl border border-border-strong bg-background shadow-xl motion-safe:animate-[sam-copilot-in_var(--motion-normal)_var(--ease-out)_both] sm:inset-x-auto sm:right-5 sm:bottom-5 sm:w-[420px]"
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
                        <HeadButton
                            label="Cerrar (Esc)"
                            onClick={() => setOpen(false)}
                        >
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
                        <div className="grid flex-1 place-items-center p-6 text-center text-xs text-fg-3">
                            {catalog.status === 'failed'
                                ? 'No pude conectar con SAM Copilot. Intenta de nuevo en unos segundos.'
                                : 'Conectando con tu flota…'}
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
