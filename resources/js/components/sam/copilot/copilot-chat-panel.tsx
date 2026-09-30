import { AlertTriangle, ChevronRight, Info, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import type {
    CopilotAssetOption,
    CopilotCatalog,
    CopilotIntent,
} from '@/types/copilot';
import {
    assetDisplay,
    CopilotComposer,
    fillTemplate,
} from './copilot-composer';
import { CopilotMessageView, CopilotThinking } from './copilot-message';
import type { useCopilotChat } from './use-copilot-chat';

type Chat = ReturnType<typeof useCopilotChat>;

interface Props {
    chat: Chat;
    catalog: CopilotCatalog;
    userName: string;
    teamName: string;
    compact?: boolean;
}

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();
}

export function CopilotChatPanel({
    chat,
    catalog,
    userName,
    teamName,
    compact = false,
}: Props) {
    const scrollRef = useRef<HTMLDivElement | null>(null);
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
    // pinned unit is whatever the last answer resolved, unless the user
    // changed it since that answer (override scoped to thread + turn).
    const resolvedAsset = useMemo(() => {
        const last = [...chat.messages]
            .reverse()
            .find((m) => m.role === 'assistant');
        const id = last?.context?.resolved?.asset_id;

        return id ? (assetsById.get(id) ?? null) : null;
    }, [chat.messages, assetsById]);

    const settledCount = chat.messages.filter((m) => !m.pending).length;
    const turnKey = `${chat.conversationId ?? 'new'}:${settledCount}`;
    const [override, setOverride] = useState<{
        key: string;
        asset: CopilotAssetOption | null;
    } | null>(null);
    const asset =
        override && override.key === turnKey ? override.asset : resolvedAsset;
    const setAsset = useCallback(
        (value: CopilotAssetOption | null) =>
            setOverride({ key: turnKey, asset: value }),
        [turnKey],
    );

    // Follows streamed text and cards while an answer is being written.
    const lastMessage = chat.messages[chat.messages.length - 1];
    const streamTick = lastMessage?.streaming
        ? lastMessage.content.length + lastMessage.blocks.length
        : 0;

    useEffect(() => {
        const el = scrollRef.current;

        if (el) {
            el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
        }
    }, [chat.messages.length, chat.busy, streamTick]);

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

    const pickAsset = useCallback(
        (option: CopilotAssetOption, intent: CopilotIntent) => {
            setAsset(option);
            const template = catalog.templates.find((t) => t.intent === intent);
            chat.send(
                template
                    ? fillTemplate(template.prompt, option)
                    : `Reporte de la unidad ${assetDisplay(option)}`,
                { assetId: option.id, intent },
            );
        },
        [catalog.templates, chat, setAsset],
    );

    const isEmpty = chat.messages.length === 0 && !chat.loading;
    const quota = catalog.quota;
    const firstName = userName.split(' ')[0] ?? userName;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div
                ref={scrollRef}
                className={cn(
                    'flex min-h-0 flex-1 flex-col overflow-y-auto',
                    compact ? 'gap-3 p-3' : 'gap-4 px-4 py-5 sm:px-6',
                )}
            >
                <div
                    className={cn(
                        'mx-auto flex w-full flex-col',
                        compact ? 'gap-3' : 'max-w-[880px] gap-4',
                    )}
                >
                    {isEmpty &&
                        (compact ? (
                            <div className="flex flex-col gap-1.5">
                                <div className="mb-1 text-sm font-medium text-fg-1">
                                    Hola {firstName}, ¿qué necesitas saber de la
                                    flota?
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
                            key={message.id}
                            showFollowups={index === chat.messages.length - 1}
                            onSuggest={suggest}
                            message={message}
                            userInitials={initials(userName)}
                            assetLabel={(id) => {
                                const a = assetsById.get(id);

                                return a ? assetDisplay(a) : null;
                            }}
                            actions={{ onPickAsset: pickAsset }}
                            onRate={chat.rate}
                            compact={compact}
                        />
                    ))}

                    {chat.busy && !chat.messages.some((m) => m.streaming) && (
                        <CopilotThinking compact={compact} />
                    )}
                    {chat.busy && (
                        <button
                            type="button"
                            onClick={chat.stop}
                            className="cursor-pointer self-start rounded-full border border-border bg-surface-2 px-2.5 py-1 text-2xs text-fg-2 hover:bg-surface-3"
                        >
                            Detener
                        </button>
                    )}
                </div>
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
                        !compact && 'max-w-[880px]',
                    )}
                >
                    {chat.error && (
                        <div className="flex items-start gap-2 rounded-md border border-severity-critical/40 bg-severity-critical/8 px-3 py-2 text-xs text-fg-1">
                            <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-severity-critical" />
                            <span className="flex-1">{chat.error}</span>
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
                        <div className="px-1 pb-0.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
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
