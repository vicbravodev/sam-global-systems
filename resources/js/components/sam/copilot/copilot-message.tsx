import { Link } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    Copy,
    FileClock,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
    Truck,
} from 'lucide-react';
import { Fragment, memo, useMemo, useState } from 'react';
import { COPILOT_SOURCE_LABELS } from '@/components/sam/copilot/copy';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type {
    CopilotAssetOption,
    CopilotIntent,
    CopilotMessage as Message,
} from '@/types/copilot';
import { CopilotBlocks } from './copilot-blocks';
import { formatTokens, formatUsd, timeOfDay } from './copilot-format';
import { formatElapsed } from './copilot-turn';

export function CopilotAvatar({ size = 28 }: { size?: number }) {
    return (
        <div
            className="grid shrink-0 place-items-center rounded-full border border-ai-accent/35 bg-ai-accent-bg text-ai-accent"
            style={{ width: size, height: size }}
        >
            <Sparkles style={{ width: size * 0.48, height: size * 0.48 }} />
        </div>
    );
}

/**
 * Renders `**negritas**` and line breaks; nothing else is interpreted.
 * Memoized: settled messages never re-split their text.
 */
const RichText = memo(function RichText({ text }: { text: string }) {
    return (
        <>
            {text.split('\n').map((line, lineIndex) => (
                <Fragment key={lineIndex}>
                    {lineIndex > 0 && <br />}
                    {line.split(/(\*\*[^*]+\*\*)/g).map((part, i) =>
                        part.startsWith('**') && part.endsWith('**') ? (
                            <strong key={i} className="font-semibold text-fg-1">
                                {part.slice(2, -2)}
                            </strong>
                        ) : (
                            <Fragment key={i}>{part}</Fragment>
                        ),
                    )}
                </Fragment>
            ))}
        </>
    );
});

const EMPTY_PENDING: NonNullable<Message['pendingCards']> = [];

interface Props {
    message: Message;
    userInitials: string;
    assetLabel?: (id: number) => string | null;
    onPickAsset?: (asset: CopilotAssetOption, intent: CopilotIntent) => void;
    onRate?: (id: number, rating: -1 | 1 | null) => void;
    compact?: boolean;
    /** Show the agent's suggested follow-ups (only the last assistant message). */
    showFollowups?: boolean;
    onSuggest?: (prompt: string) => void;
}

/**
 * One chat entry. Memoized with stable props from the panel: while an answer
 * streams only the live entry re-renders. Entries sent from this client
 * (`clientKey`) animate in once; history loads render still.
 */
export const CopilotMessageView = memo(function CopilotMessageView({
    message,
    userInitials,
    assetLabel,
    onPickAsset,
    onRate,
    compact = false,
    showFollowups = false,
    onSuggest,
}: Props) {
    const live = message.clientKey !== undefined;

    if (message.role === 'user') {
        const pinnedAsset =
            message.context?.asset_id && assetLabel
                ? assetLabel(message.context.asset_id)
                : null;

        return (
            <div
                className={cn(
                    'flex w-full flex-row-reverse items-start gap-2.5 self-end',
                    live && 'sam-copilot-rise',
                )}
            >
                <div className="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-2xs font-semibold text-primary-foreground">
                    {userInitials}
                </div>
                <div className="max-w-[85%] rounded-xl border border-primary/40 bg-primary/15 px-3.5 py-2 text-sm text-fg-1">
                    {pinnedAsset && (
                        <span className="mb-1 inline-flex items-center gap-1 rounded-full bg-surface-1/70 px-1.5 py-0.5 font-mono text-3xs text-fg-2">
                            <Truck className="size-2.5" /> {pinnedAsset}
                        </span>
                    )}
                    <div className="break-words whitespace-pre-wrap">
                        {message.content}
                    </div>
                </div>
            </div>
        );
    }

    return (
        <AssistantMessage
            message={message}
            live={live}
            compact={compact}
            showFollowups={showFollowups}
            onPickAsset={onPickAsset}
            onRate={onRate}
            onSuggest={onSuggest}
        />
    );
});

function AssistantMessage({
    message,
    live,
    compact,
    showFollowups,
    onPickAsset,
    onRate,
    onSuggest,
}: {
    message: Message;
    live: boolean;
    compact: boolean;
    showFollowups: boolean;
    onPickAsset?: Props['onPickAsset'];
    onRate?: Props['onRate'];
    onSuggest?: Props['onSuggest'];
}) {
    const [showSources, setShowSources] = useState(false);
    const [copied, setCopied] = useState(false);
    const actions = useMemo(
        () => ({ onPickAsset, compact }),
        [onPickAsset, compact],
    );

    const streaming = message.streaming === true;
    const phase = message.phase ?? 'done';
    const settled = !streaming;

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(message.content);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    const usage = message.usage;
    const tokens = usage ? usage.inputTokens + usage.outputTokens : 0;
    const followups = showFollowups ? message.followups : [];
    const activity = useMemo(() => activityOf(message), [message]);

    return (
        <div
            className={cn(
                'flex w-full items-start gap-2.5',
                live && 'sam-copilot-rise',
            )}
        >
            <CopilotAvatar size={compact ? 24 : 28} />
            <article
                aria-busy={streaming}
                aria-label="Respuesta de SAM Copilot"
                className={cn(
                    'min-w-0 flex-1 rounded-xl border bg-surface-1 text-sm leading-relaxed text-fg-1 transition-colors duration-(--motion-normal) ease-(--ease-out)',
                    compact ? 'px-3 py-2.5' : 'px-4 py-3',
                    // Accent while it works, neutral once it's done.
                    streaming ? 'border-ai-accent/40' : 'border-border',
                )}
            >
                <ActivityLine activity={activity} />

                {message.content !== '' && (
                    <div className="mt-1.5 break-words text-fg-1">
                        <RichText text={message.content} />
                        {phase === 'writing' && (
                            <span
                                aria-hidden
                                className="ml-0.5 inline-block h-3.5 w-1 translate-y-0.5 rounded-sm bg-ai-accent motion-safe:animate-pulse"
                            />
                        )}
                    </div>
                )}

                {settled &&
                    message.content === '' &&
                    message.blocks.length === 0 && (
                        <div className="mt-1.5 text-xs text-fg-3">
                            {message.partial
                                ? 'Se detuvo antes de responder.'
                                : 'No se generó una respuesta. Intenta de nuevo.'}
                        </div>
                    )}

                <CopilotBlocks
                    blocks={message.blocks}
                    pending={message.pendingCards ?? EMPTY_PENDING}
                    live={live}
                    actions={actions}
                />

                {followups.length > 0 && (
                    // Laid out as soon as they arrive (hidden), revealed with
                    // the footer at done: nothing shifts at completion.
                    <div
                        inert={streaming}
                        aria-hidden={streaming}
                        className={cn(
                            'sam-copilot-reveal mt-3 flex flex-wrap gap-1.5',
                            streaming ? 'opacity-0' : 'opacity-100',
                        )}
                    >
                        {followups.map((question, index) => (
                            <button
                                key={`${index}-${question}`}
                                type="button"
                                onClick={() => onSuggest?.(question)}
                                className="cursor-pointer rounded-full border border-ai-accent/35 bg-ai-accent-bg px-2.5 py-1 text-left text-2xs text-ai-accent transition-transform duration-(--motion-fast) ease-(--ease-out) hover:bg-surface-3 active:scale-97"
                            >
                                {question}
                            </button>
                        ))}
                    </div>
                )}

                {/* Footer row: reserved from the first frame, filled and
                    faded in at done (same height, no layout shift). */}
                <div
                    inert={streaming}
                    aria-hidden={streaming}
                    className={cn(
                        'sam-copilot-reveal mt-3 flex min-h-6 flex-wrap items-center gap-1.5 border-t border-dashed border-border pt-2',
                        streaming ? 'opacity-0' : 'opacity-100',
                    )}
                >
                    {settled && (
                        <>
                            {message.sources.length > 0 && (
                                <button
                                    type="button"
                                    aria-expanded={showSources}
                                    onClick={() => setShowSources((v) => !v)}
                                    className="inline-flex cursor-pointer items-center gap-1 rounded-full border border-border bg-surface-2 px-2 py-0.5 text-2xs font-medium text-fg-2 hover:bg-surface-3"
                                >
                                    <FileClock className="size-3" />
                                    {message.sources.length} fuente
                                    {message.sources.length > 1
                                        ? 's'
                                        : ''} ·{' '}
                                    {showSources ? 'ocultar' : 'ver'}
                                </button>
                            )}
                            <span className="flex-1" />
                            {usage && (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span className="cursor-default font-mono text-3xs text-fg-3">
                                            {tokens > 0
                                                ? `${formatTokens(tokens)} tokens`
                                                : 'sin LLM'}
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {usage.model ??
                                            'Respuesta generada desde datos (sin modelo de lenguaje)'}
                                        {tokens > 0 &&
                                            ` · ${usage.inputTokens} entrada / ${usage.outputTokens} salida · ${formatUsd(usage.cost)}`}
                                        {` · ${formatElapsed(usage.latencyMs)} en servidor`}
                                    </TooltipContent>
                                </Tooltip>
                            )}
                            {message.id > 0 && (
                                <>
                                    <FootButton
                                        label="Útil"
                                        pressed={message.feedback === 1}
                                        onClick={() =>
                                            onRate?.(
                                                message.id,
                                                message.feedback === 1
                                                    ? null
                                                    : 1,
                                            )
                                        }
                                    >
                                        <ThumbsUp className="size-3" />
                                    </FootButton>
                                    <FootButton
                                        label="No fue útil"
                                        pressed={message.feedback === -1}
                                        onClick={() =>
                                            onRate?.(
                                                message.id,
                                                message.feedback === -1
                                                    ? null
                                                    : -1,
                                            )
                                        }
                                    >
                                        <ThumbsDown className="size-3" />
                                    </FootButton>
                                </>
                            )}
                            {message.content !== '' && (
                                <FootButton
                                    label={copied ? 'Copiado' : 'Copiar'}
                                    onClick={copy}
                                >
                                    {copied ? (
                                        <Check className="size-3" />
                                    ) : (
                                        <Copy className="size-3" />
                                    )}
                                </FootButton>
                            )}
                            <span className="font-mono text-3xs text-fg-3">
                                {timeOfDay(message.createdAt)}
                            </span>
                        </>
                    )}
                </div>

                {showSources && (
                    <div className="mt-2 flex flex-col gap-1">
                        {message.sources.map((source) => (
                            <Link
                                key={`${source.kind}-${source.id}`}
                                href={source.href}
                                className="flex items-center gap-2 rounded-sm border border-border bg-surface-2 px-2.5 py-1.5 text-xs hover:bg-surface-3"
                            >
                                <span className="font-mono text-3xs text-fg-3 uppercase">
                                    {COPILOT_SOURCE_LABELS[source.kind]}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-fg-1">
                                    {source.label}
                                </span>
                            </Link>
                        ))}
                    </div>
                )}
            </article>
        </div>
    );
}

// ---------- activity line ----------

interface Activity {
    /** A new key crossfades; the same key updates in place. */
    key: string;
    tone: 'busy' | 'done' | 'partial';
    text: string;
    title?: string;
}

/** What the line says for each real stream phase. */
function activityOf(message: Message): Activity {
    switch (message.phase ?? 'done') {
        case 'thinking':
            return { key: 'thinking', tone: 'busy', text: 'Pensando…' };
        case 'tool': {
            const active = message.activeTools ?? [];
            const latest = active[active.length - 1];
            const more = active.length > 1 ? ` +${active.length - 1}` : '';

            return {
                key: `tool:${latest?.toolCallId ?? ''}`,
                tone: 'busy',
                text: `${latest?.label ?? 'Consultando datos…'}${more}`,
            };
        }
        case 'writing':
            return { key: 'writing', tone: 'busy', text: 'Escribiendo…' };
        case 'finishing':
            return {
                key: 'finishing',
                tone: 'busy',
                text: 'Preparando sugerencias…',
            };
        default: {
            const ms = message.elapsedMs ?? message.usage?.latencyMs;
            const count = message.toolCount ?? message.tools.length;
            const text = [
                message.partial ? 'Respuesta incompleta' : 'Listo',
                count > 0
                    ? `${count} ${count === 1 ? 'consulta' : 'consultas'}`
                    : null,
                ms !== undefined && ms > 0 ? formatElapsed(ms) : null,
            ]
                .filter(Boolean)
                .join(' · ');

            return {
                key: 'done',
                tone: message.partial ? 'partial' : 'done',
                text,
                title:
                    message.tools.length > 0
                        ? message.tools.map((t) => t.label).join(' · ')
                        : undefined,
            };
        }
    }
}

/**
 * Fixed-height status line at the top of every answer: mounts with the
 * bubble, never unmounts, and crossfades (opacity only, 120 ms) between
 * phases so nothing below it moves.
 */
function ActivityLine({ activity }: { activity: Activity }) {
    const [shown, setShown] = useState(activity.key);
    const [leaving, setLeaving] = useState<Activity | null>(null);
    const [last, setLast] = useState(activity);

    // Render-time sync (react.dev "adjusting state on prop change").
    if (shown !== activity.key) {
        setLeaving(last);
        setShown(activity.key);
    }

    if (last !== activity) {
        setLast(activity);
    }

    return (
        <div className="relative h-5 overflow-hidden">
            {leaving && (
                <ActivityContent
                    key={`out:${leaving.key}`}
                    activity={leaving}
                    className="sam-copilot-fade-out absolute inset-0"
                    onAnimationEnd={() => setLeaving(null)}
                    hidden
                />
            )}
            <ActivityContent
                key={activity.key}
                activity={activity}
                className={leaving ? 'sam-copilot-fade' : undefined}
            />
        </div>
    );
}

function ActivityContent({
    activity,
    className,
    onAnimationEnd,
    hidden = false,
}: {
    activity: Activity;
    className?: string;
    onAnimationEnd?: () => void;
    hidden?: boolean;
}) {
    return (
        <div
            aria-hidden={hidden || undefined}
            onAnimationEnd={onAnimationEnd}
            title={activity.title}
            className={cn(
                'flex h-5 items-center gap-1.5 font-mono text-3xs text-fg-3',
                className,
            )}
        >
            {activity.tone === 'busy' ? (
                <span className="grid size-3 shrink-0 place-items-center">
                    <span className="size-1.5 rounded-full bg-ai-accent motion-safe:animate-pulse" />
                </span>
            ) : activity.tone === 'done' ? (
                <Check className="size-3 shrink-0 text-health-ok" />
            ) : (
                <AlertCircle className="size-3 shrink-0 text-fg-3" />
            )}
            <span className="min-w-0 truncate">{activity.text}</span>
        </div>
    );
}

function FootButton({
    children,
    label,
    pressed,
    onClick,
}: {
    children: React.ReactNode;
    label: string;
    /** Only for toggles (ratings); plain actions leave it undefined. */
    pressed?: boolean;
    onClick: () => void;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    aria-pressed={pressed}
                    onClick={onClick}
                    className={cn(
                        'grid size-6 cursor-pointer place-items-center rounded-sm text-fg-3 transition-transform duration-(--motion-fast) ease-(--ease-out) hover:bg-surface-2 hover:text-fg-1 active:scale-97',
                        pressed && 'bg-ai-accent-bg text-ai-accent',
                    )}
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
