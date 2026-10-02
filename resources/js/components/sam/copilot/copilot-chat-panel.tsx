import {
    AlertTriangle,
    ArrowDown,
    ChevronRight,
    Info,
    RotateCcw,
    Sparkles,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { cn } from '@/lib/utils';
import type {
    CopilotAssetOption,
    CopilotCatalog,
    CopilotIntent,
    CopilotMessage,
} from '@/types/copilot';
import {
    assetDisplay,
    CopilotComposer,
    fillTemplate,
} from './copilot-composer';
import { CopilotMessageView } from './copilot-message';
import type { useCopilotChat } from './use-copilot-chat';

type Chat = ReturnType<typeof useCopilotChat>;

interface Props {
    chat: Chat;
    catalog: CopilotCatalog;
    userName: string;
    teamName: string;
    compact?: boolean;
}

/** Within this distance of the bottom the thread follows new content. */
const FOLLOW_THRESHOLD = 80;

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();
}

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * Settled answers only: a live draft never unpins the composer's unit, and
 * neither does a stopped/cut answer that resolved no unit.
 */
function lastSettledAnswer(
    messages: CopilotMessage[],
): CopilotMessage | undefined {
    for (let i = messages.length - 1; i >= 0; i--) {
        const m = messages[i];

        if (
            m.role === 'assistant' &&
            !m.streaming &&
            !(m.partial && !m.context?.resolved)
        ) {
            return m;
        }
    }

    return undefined;
}

export function CopilotChatPanel({
    chat,
    catalog,
    userName,
    teamName,
    compact = false,
}: Props) {
    const scrollRef = useRef<HTMLDivElement | null>(null);
    const contentRef = useRef<HTMLDivElement | null>(null);
    const [draft, setDraft] = useState<{
        text: string;
        intent: CopilotIntent | null;
        nonce: number;
    } | null>(null);

    const assetsById = useMemo(
        () => new Map(catalog.assets.map((a) => [a.id, a])),
        [catalog.assets],
    );

    // A thread that was about one unit keeps it pinned in the composer: the
    // pinned unit is whatever the last settled answer resolved, unless the
    // user changed it since (override scoped to thread + answer count).
    const lastAnswer = lastSettledAnswer(chat.messages);
    const resolvedId = lastAnswer?.context?.resolved?.asset_id ?? null;
    const resolvedAsset = resolvedId
        ? (assetsById.get(resolvedId) ?? null)
        : null;
    const settledAnswers = chat.messages.filter(
        (m) => m.role === 'assistant' && !m.streaming,
    ).length;
    const [override, setOverride] = useState<{
        thread: number;
        from: number;
        until: number;
        asset: CopilotAssetOption | null;
    } | null>(null);
    const asset =
        override &&
        override.thread === chat.thread &&
        settledAnswers >= override.from &&
        settledAnswers <= override.until
            ? override.asset
            : resolvedAsset;
    const { busy, thread } = chat;
    const setAsset = useCallback(
        (value: CopilotAssetOption | null) =>
            // Changed while an answer streams: it is meant for the next
            // question, so it also outlives the answer landing.
            setOverride({
                thread,
                from: settledAnswers,
                until: settledAnswers + (busy ? 1 : 0),
                asset: value,
            }),
        [busy, settledAnswers, thread],
    );

    // ---- scroll: follow only when the reader is at the bottom ----
    const stickRef = useRef(true);
    const lastTopRef = useRef(0);
    const busyRef = useRef(chat.busy);
    const [hasUnseen, setHasUnseen] = useState(false);

    useEffect(() => {
        busyRef.current = chat.busy;
    }, [chat.busy]);

    /** DOM only (safe in layout effects); onScroll clears the pill. */
    const pinToBottom = useCallback(() => {
        const el = scrollRef.current;
        stickRef.current = true;

        if (el) {
            el.scrollTop = el.scrollHeight;
            lastTopRef.current = el.scrollTop;
        }
    }, []);

    /** The "Nueva respuesta" pill: smooth unless reduced motion. */
    const jumpToBottom = useCallback(() => {
        const el = scrollRef.current;
        stickRef.current = true;
        setHasUnseen(false);

        if (el) {
            el.scrollTo({
                top: el.scrollHeight,
                behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            });
        }
    }, []);

    const onScroll = useCallback(() => {
        const el = scrollRef.current;

        if (!el) {
            return;
        }

        const distance = el.scrollHeight - el.scrollTop - el.clientHeight;
        const movedUp = el.scrollTop < lastTopRef.current - 1;
        lastTopRef.current = el.scrollTop;

        // Any upward scroll by the reader lets go; content shrinking at the
        // bottom (scrollTop clamps but distance stays ~0) does not.
        if (movedUp && distance >= 4) {
            stickRef.current = false;
        } else if (distance < FOLLOW_THRESHOLD) {
            stickRef.current = true;
        }

        if (stickRef.current) {
            setHasUnseen(false);
        }
    }, []);

    // Content grows (deltas, cards, maps loading): pin to the bottom within
    // the same frame, instantly (no smooth scroll per delta), only if the
    // reader is there; otherwise offer the jump pill.
    useEffect(() => {
        const el = scrollRef.current;
        const content = contentRef.current;

        if (!el || !content || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(() => {
            if (stickRef.current) {
                el.scrollTop = el.scrollHeight;
                lastTopRef.current = el.scrollTop;
            } else if (busyRef.current) {
                setHasUnseen(true);
            }
        });
        observer.observe(content);

        return () => observer.disconnect();
    }, []);

    // A new thread (reset/load) starts at the bottom.
    useLayoutEffect(() => {
        pinToBottom();
    }, [chat.thread, pinToBottom]);

    // Sending is an explicit jump: always go down to the new question.
    const lastQuestionKey = useMemo(() => {
        for (let i = chat.messages.length - 1; i >= 0; i--) {
            if (chat.messages[i].role === 'user') {
                return chat.messages[i].clientKey ?? null;
            }
        }

        return null;
    }, [chat.messages]);

    useLayoutEffect(() => {
        if (lastQuestionKey) {
            pinToBottom();
        }
    }, [lastQuestionKey, pinToBottom]);

    const sampleAsset = asset ?? catalog.assets[0] ?? null;

    const suggest = useCallback(
        (prompt: string) => {
            setDraft({
                text: fillTemplate(prompt, sampleAsset),
                intent: null,
                nonce: Date.now(),
            });

            if (prompt.includes('{asset}') && sampleAsset) {
                setAsset(sampleAsset);
            }
        },
        [sampleAsset, setAsset],
    );

    const { send } = chat;
    const pickAsset = useCallback(
        (option: CopilotAssetOption, intent: CopilotIntent) => {
            setAsset(option);
            const template = catalog.templates.find((t) => t.intent === intent);
            void send(
                template
                    ? fillTemplate(template.prompt, option)
                    : `Reporte de la unidad ${assetDisplay(option)}`,
                { assetId: option.id, intent },
            );
        },
        [catalog.templates, send, setAsset],
    );

    const assetLabel = useCallback(
        (id: number) => {
            const a = assetsById.get(id);

            return a ? assetDisplay(a) : null;
        },
        [assetsById],
    );

    const isEmpty = chat.messages.length === 0 && !chat.loading;
    const quota = catalog.quota;
    const firstName = userName.split(' ')[0] ?? userName;
    const userInitials = initials(userName);
    // Screen readers hear transitions, never tokens.
    const announcement = chat.busy
        ? 'SAM Copilot está respondiendo…'
        : chat.outcome === 'done'
          ? 'Respuesta lista'
          : chat.outcome === 'partial'
            ? 'Respuesta incompleta'
            : chat.outcome === 'error'
              ? `No se pudo responder. ${chat.error ?? ''}`.trim()
              : '';

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="sr-only" aria-live="polite" role="status">
                {announcement}
            </div>
            <div className="relative flex min-h-0 flex-1 flex-col">
                <div
                    ref={scrollRef}
                    onScroll={onScroll}
                    role="log"
                    aria-live="off"
                    aria-label="Conversación con SAM Copilot"
                    className={cn(
                        'flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain',
                        compact ? 'p-3' : 'px-4 py-5 sm:px-6',
                    )}
                >
                    <div
                        ref={contentRef}
                        className={cn(
                            'mx-auto flex w-full flex-col',
                            compact ? 'gap-3' : 'max-w-220 gap-4',
                        )}
                    >
                        {isEmpty &&
                            (compact ? (
                                <div className="flex flex-col gap-1.5">
                                    <div className="mb-1 text-sm font-medium text-fg-1">
                                        Hola {firstName}, ¿qué necesitas saber
                                        de la flota?
                                    </div>
                                    {catalog.suggestions
                                        .flatMap((g) => g.prompts)
                                        .slice(0, 4)
                                        .map((prompt) => (
                                            <button
                                                key={prompt}
                                                type="button"
                                                onClick={() => suggest(prompt)}
                                                className="flex cursor-pointer items-center gap-2 rounded-md border border-border bg-surface-2 px-2.5 py-2 text-left text-xs text-fg-1 hover:bg-surface-3"
                                            >
                                                <span className="flex-1">
                                                    {fillTemplate(
                                                        prompt,
                                                        sampleAsset,
                                                    )}
                                                </span>
                                                <ChevronRight className="size-3 text-fg-3" />
                                            </button>
                                        ))}
                                </div>
                            ) : (
                                <EmptyHero
                                    firstName={firstName}
                                    teamName={teamName}
                                    catalog={catalog}
                                    sampleAsset={sampleAsset}
                                    onSuggest={suggest}
                                />
                            ))}

                        {chat.loading && (
                            <div className="flex flex-col gap-3">
                                {[0, 1].map((i) => (
                                    <div
                                        key={i}
                                        className="h-20 animate-pulse rounded-xl bg-surface-2"
                                    />
                                ))}
                            </div>
                        )}

                        {chat.messages.map((message, index) => (
                            <CopilotMessageView
                                // Live turns keep their client key from the
                                // optimistic entry to the stored one.
                                key={message.clientKey ?? message.id}
                                showFollowups={
                                    index === chat.messages.length - 1
                                }
                                onSuggest={suggest}
                                message={message}
                                userInitials={userInitials}
                                assetLabel={assetLabel}
                                onPickAsset={pickAsset}
                                onRate={chat.rate}
                                compact={compact}
                            />
                        ))}
                    </div>
                </div>

                {hasUnseen && (
                    <button
                        type="button"
                        onClick={jumpToBottom}
                        className="sam-copilot-rise absolute bottom-3 left-1/2 inline-flex -translate-x-1/2 cursor-pointer items-center gap-1 rounded-full border border-border-strong bg-surface-1 px-3 py-1 text-2xs font-medium text-fg-1 shadow-md transition-transform duration-(--motion-fast) ease-(--ease-out) hover:bg-surface-2 active:scale-97"
                    >
                        <ArrowDown className="size-3" />
                        Nueva respuesta
                    </button>
                )}
            </div>

            <div
                className={cn(
                    'shrink-0 border-t border-border bg-background',
                    compact ? 'p-2.5' : 'px-4 pt-3 pb-3 sm:px-6',
                )}
            >
                <div
                    className={cn(
                        'mx-auto flex w-full flex-col gap-2',
                        !compact && 'max-w-220',
                    )}
                >
                    {chat.error && (
                        <div
                            role="alert"
                            className="flex items-start gap-2 rounded-md border border-severity-critical/40 bg-severity-critical/8 px-3 py-2 text-xs text-fg-1"
                        >
                            <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-severity-critical" />
                            <span className="flex-1">{chat.error}</span>
                            {chat.canRetry && !chat.busy && (
                                <button
                                    type="button"
                                    onClick={chat.retry}
                                    className="inline-flex cursor-pointer items-center gap-1 rounded-sm font-medium text-fg-1 underline-offset-2 hover:underline"
                                >
                                    <RotateCcw className="size-3" />
                                    {chat.retryMode === 'reload'
                                        ? 'Recargar conversación'
                                        : 'Reintentar'}
                                </button>
                            )}
                            <button
                                type="button"
                                aria-label="Cerrar"
                                onClick={chat.dismissError}
                                className="cursor-pointer text-fg-3 hover:text-fg-1"
                            >
                                <X className="size-3.5" />
                            </button>
                        </div>
                    )}
                    {quota.percent !== null && quota.percent >= 80 && (
                        <div className="flex items-center gap-2 rounded-md border border-severity-high/40 bg-severity-high/8 px-3 py-1.5 text-2xs text-fg-2">
                            <Info className="size-3 text-severity-high" />
                            {quota.percent >= 100
                                ? `Tu empresa superó las ${quota.included} consultas incluidas este mes; las adicionales se facturan como excedente.`
                                : `Tu empresa usó el ${quota.percent}% de las consultas incluidas este mes.`}
                        </div>
                    )}
                    <CopilotComposer
                        assets={catalog.assets}
                        templates={catalog.templates}
                        busy={chat.busy}
                        compact={compact}
                        autoFocus={compact}
                        asset={asset}
                        onAssetChange={setAsset}
                        onSend={chat.send}
                        onStop={chat.stop}
                        draft={draft}
                    />
                    {!compact && (
                        <div className="flex items-center gap-1.5 text-3xs text-fg-3">
                            <Info className="size-3 shrink-0" />
                            <span>
                                SAM Copilot responde con datos en vivo de{' '}
                                <b className="font-medium text-fg-2">
                                    {teamName}
                                </b>{' '}
                                y puede equivocarse: verifica decisiones
                                críticas. Cada consulta queda en auditoría a tu
                                nombre.
                            </span>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function EmptyHero({
    firstName,
    teamName,
    catalog,
    sampleAsset,
    onSuggest,
}: {
    firstName: string;
    teamName: string;
    catalog: CopilotCatalog;
    sampleAsset: CopilotAssetOption | null;
    onSuggest: (prompt: string) => void;
}) {
    return (
        <div className="flex flex-col items-center gap-7 py-6 motion-safe:animate-[sam-copilot-in_var(--motion-slow)_var(--ease-out)_both]">
            <div className="flex flex-col items-center text-center">
                <div className="grid size-14 place-items-center rounded-full border border-ai-accent/35 bg-ai-accent-bg text-ai-accent shadow-[0_0_0_6px_color-mix(in_oklch,var(--ai-accent)_8%,transparent)]">
                    <Sparkles className="size-6" strokeWidth={1.5} />
                </div>
                <h2 className="mt-4 text-xl font-semibold text-fg-1">
                    Hola {firstName}, pregúntale a tu flota
                </h2>
                <p className="mt-1.5 max-w-lg text-sm text-fg-3">
                    SAM Copilot trabaja como tu monitorista: consulta en vivo la
                    ubicación, telemetría, combustible, video, pánicos e
                    incidentes de {teamName}. Elige una consulta guiada o
                    escribe como le hablarías a tu central.
                </p>
            </div>
            <div className="grid w-full max-w-3xl grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
                {catalog.suggestions.map((group) => (
                    <div key={group.group} className="flex flex-col gap-1">
                        <div className="sam-caps px-1 pb-0.5">
                            {group.group}
                        </div>
                        {group.prompts.map((prompt) => (
                            <button
                                key={prompt}
                                type="button"
                                onClick={() => onSuggest(prompt)}
                                className="flex cursor-pointer items-center gap-2 rounded-md border border-border bg-surface-1 px-3 py-2.5 text-left text-sm text-fg-1 transition-colors hover:border-border-strong hover:bg-surface-2"
                            >
                                <span className="flex-1">
                                    {fillTemplate(prompt, sampleAsset)}
                                </span>
                                <ChevronRight className="size-3.5 text-fg-3" />
                            </button>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
