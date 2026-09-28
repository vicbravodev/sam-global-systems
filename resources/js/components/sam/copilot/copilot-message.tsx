import { Link } from '@inertiajs/react';
import {
    Check,
    Copy,
    FileClock,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
    Truck,
} from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { CopilotMessage as Message } from '@/types/copilot';
import { CopilotBlocks } from './copilot-blocks';
import type { BlockActions } from './copilot-blocks';
import { formatTokens, formatUsd, timeOfDay } from './copilot-format';

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

/** Renders `**negritas**` and line breaks; nothing else is interpreted. */
function RichText({ text }: { text: string }) {
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
}

interface Props {
    message: Message;
    userInitials: string;
    assetLabel?: (id: number) => string | null;
    actions: BlockActions;
    onRate?: (id: number, rating: -1 | 1 | null) => void;
    compact?: boolean;
}

export function CopilotMessageView({
    message,
    userInitials,
    assetLabel,
    actions,
    onRate,
    compact = false,
}: Props) {
    const [showSources, setShowSources] = useState(false);
    const [copied, setCopied] = useState(false);

    if (message.role === 'user') {
        const pinnedAsset =
            message.context?.asset_id && assetLabel
                ? assetLabel(message.context.asset_id)
                : null;

        return (
            <div className="flex w-full flex-row-reverse items-start gap-2.5 self-end motion-safe:animate-[sam-copilot-in_var(--motion-fast)_var(--ease-out)_both]">
                <div className="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-2xs font-semibold text-primary-foreground">
                    {userInitials}
                </div>
                <div
                    className={cn(
                        'max-w-[85%] rounded-xl border border-primary/40 bg-primary/15 px-3.5 py-2 text-sm text-fg-1',
                        message.pending && 'opacity-80',
                    )}
                >
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

    return (
        <div className="flex w-full items-start gap-2.5 motion-safe:animate-[sam-copilot-in_var(--motion-normal)_var(--ease-out)_both]">
            <CopilotAvatar size={compact ? 24 : 28} />
            <div
                className={cn(
                    'min-w-0 flex-1 rounded-xl border border-border bg-surface-1 text-sm leading-relaxed text-fg-1',
                    compact ? 'px-3 py-2.5' : 'px-4 py-3',
                )}
            >
                {message.tools.length > 0 && (
                    <div className="mb-2 flex flex-wrap gap-1">
                        {message.tools.map((tool) => (
                            <span
                                key={tool.tool}
                                className="inline-flex items-center gap-1 rounded-full border border-border bg-surface-2 px-2 py-0.5 font-mono text-3xs text-fg-2"
                            >
                                <Check className="size-2.5 text-health-ok" />
                                {tool.label}
                            </span>
                        ))}
                    </div>
                )}

                <div className="break-words text-fg-1">
                    <RichText text={message.content} />
                </div>

                <CopilotBlocks
                    blocks={message.blocks}
                    actions={{ ...actions, compact }}
                />

                <div className="mt-3 flex flex-wrap items-center gap-1.5 border-t border-dashed border-border pt-2">
                    {message.sources.length > 0 && (
                        <button
                            type="button"
                            onClick={() => setShowSources((v) => !v)}
                            className="inline-flex cursor-pointer items-center gap-1 rounded-full border border-border bg-surface-2 px-2 py-0.5 text-2xs font-medium text-fg-2 hover:bg-surface-3"
                        >
                            <FileClock className="size-3" />
                            {message.sources.length} fuente
                            {message.sources.length > 1 ? 's' : ''} ·{' '}
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
                                        : 'sin LLM'}{' '}
                                    · {(usage.latencyMs / 1000).toFixed(1)} s
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>
                                {usage.model ??
                                    'Respuesta generada desde datos (sin modelo de lenguaje)'}
                                {tokens > 0 &&
                                    ` · ${usage.inputTokens} entrada / ${usage.outputTokens} salida · ${formatUsd(usage.cost)}`}
                            </TooltipContent>
                        </Tooltip>
                    )}
                    <FootButton
                        label="Útil"
                        active={message.feedback === 1}
                        onClick={() =>
                            onRate?.(
                                message.id,
                                message.feedback === 1 ? null : 1,
                            )
                        }
                    >
                        <ThumbsUp className="size-3" />
                    </FootButton>
                    <FootButton
                        label="No fue útil"
                        active={message.feedback === -1}
                        onClick={() =>
                            onRate?.(
                                message.id,
                                message.feedback === -1 ? null : -1,
                            )
                        }
                    >
                        <ThumbsDown className="size-3" />
                    </FootButton>
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
                    <span className="font-mono text-3xs text-fg-3">
                        {timeOfDay(message.createdAt)}
                    </span>
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
                                    {SOURCE_LABELS[source.kind]}
                                </span>
                                <span className="min-w-0 flex-1 truncate text-fg-1">
                                    {source.label}
                                </span>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

const SOURCE_LABELS: Record<string, string> = {
    asset: 'Unidad',
    incident: 'Incidente',
    event: 'Evento',
    driver: 'Conductor',
};

function FootButton({
    children,
    label,
    active = false,
    onClick,
}: {
    children: React.ReactNode;
    label: string;
    active?: boolean;
    onClick: () => void;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    aria-pressed={active}
                    onClick={onClick}
                    className={cn(
                        'grid size-6 cursor-pointer place-items-center rounded-sm text-fg-3 hover:bg-surface-2 hover:text-fg-1',
                        active && 'bg-ai-accent-bg text-ai-accent',
                    )}
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

const THINKING_STEPS = [
    'Interpretando la pregunta…',
    'Consultando unidades y telemetría…',
    'Revisando eventos e incidentes…',
    'Preparando la respuesta…',
];

export function CopilotThinking({ compact = false }: { compact?: boolean }) {
    const [step, setStep] = useState(0);

    useEffect(() => {
        const timer = window.setInterval(
            () => setStep((s) => Math.min(s + 1, THINKING_STEPS.length - 1)),
            900,
        );

        return () => window.clearInterval(timer);
    }, []);

    return (
        <div
            className="flex items-start gap-2.5"
            role="status"
            aria-live="polite"
        >
            <CopilotAvatar size={compact ? 24 : 28} />
            <div className="inline-flex items-center gap-2 rounded-xl border border-border bg-surface-1 px-3.5 py-2.5 text-xs text-fg-3">
                <span className="font-mono">{THINKING_STEPS[step]}</span>
                <span className="inline-flex gap-0.5">
                    {[0, 1, 2].map((i) => (
                        <span
                            key={i}
                            className="size-1.5 rounded-full bg-ai-accent motion-safe:animate-[sam-copilot-dots_1.2s_ease-in-out_infinite]"
                            style={{ animationDelay: `${i * 150}ms` }}
                        />
                    ))}
                </span>
            </div>
        </div>
    );
}
