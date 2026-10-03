import { router, usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import copilotRoutes from '@/routes/copilot';
import {
    CopilotBubbleConnecting,
    CopilotBubbleFrame,
} from './copilot-bubble-frame';

// The chat panel (blocks, composer, stream reader) only ships once the bubble
// opens; hovering or focusing the launcher starts the download early.
const loadBubble = () => import('./copilot-bubble');
const CopilotBubble = lazy(loadBubble);

function prefetchBubble(): void {
    void loadBubble();
}

/**
 * "Pregúntale a SAM": floating Copilot bubble available on every ops page
 * (⌘J / Ctrl+J). Hidden on the full Copilot page and for users whose role
 * or tenant plan doesn't include the module.
 */
export function CopilotLauncher() {
    const page = usePage();
    const team = page.props.currentTeam;
    const teamSlug = team?.slug ?? '';
    const onCopilotPage =
        page.url.split(/[?#]/)[0] === copilotRoutes.index.url(teamSlug);
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

function LauncherForTeam({ teamSlug }: { teamSlug: string }) {
    const [open, setOpen] = useState(false);
    /** The bubble mounts on first open and stays mounted (keeps the thread). */
    const [mounted, setMounted] = useState(false);
    const launcherRef = useRef<HTMLButtonElement | null>(null);
    /** Where focus was when the bubble opened: it goes back there on close. */
    const returnFocusRef = useRef<HTMLElement | null>(null);

    const show = useCallback(() => {
        const current = document.activeElement;
        returnFocusRef.current =
            current instanceof HTMLElement && current !== document.body
                ? current
                : null;
        setMounted(true);
        setOpen(true);
    }, []);

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

    const openFull = useCallback(
        (conversationId: number | null) => {
            returnFocusRef.current = null;
            setOpen(false);
            router.visit(
                copilotRoutes.index(teamSlug, {
                    query: conversationId ? { c: conversationId } : undefined,
                }),
            );
        },
        [teamSlug],
    );

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
                        onPointerEnter={prefetchBubble}
                        onFocus={prefetchBubble}
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

            {mounted && (
                <Suspense
                    fallback={
                        open ? (
                            <CopilotBubbleFrame onClose={close}>
                                <CopilotBubbleConnecting />
                            </CopilotBubbleFrame>
                        ) : null
                    }
                >
                    <CopilotBubble
                        teamSlug={teamSlug}
                        open={open}
                        onClose={close}
                        onOpenFull={openFull}
                    />
                </Suspense>
            )}
        </>
    );
}
