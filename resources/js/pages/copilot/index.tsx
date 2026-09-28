import { Head, Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    MessageSquarePlus,
    Pin,
    PinOff,
    Plug,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { CopilotChatPanel } from '@/components/sam/copilot/copilot-chat-panel';
import { timeAgo } from '@/components/sam/copilot/copilot-format';
import { useCopilotChat } from '@/components/sam/copilot/use-copilot-chat';
import { cn } from '@/lib/utils';
import type {
    CopilotCatalog,
    CopilotConversation,
    CopilotQuota,
} from '@/types/copilot';

type PageProps = CopilotCatalog & { initialConversationId: number | null };

function sortConversations(list: CopilotConversation[]): CopilotConversation[] {
    return [...list].sort((a, b) => {
        if (a.isPinned !== b.isPinned) {
            return a.isPinned ? -1 : 1;
        }

        return (b.lastMessageAt ?? '').localeCompare(a.lastMessageAt ?? '');
    });
}

export default function CopilotIndex() {
    const page = usePage();
    const props = page.props as unknown as PageProps;
    const team = page.props.currentTeam;
    const user = page.props.auth.user;
    const teamSlug = team?.slug ?? '';

    const [conversations, setConversations] = useState(() =>
        sortConversations(props.conversations),
    );
    const [quota, setQuota] = useState<CopilotQuota>(props.quota);

    const upsertConversation = useCallback(
        (conversation: CopilotConversation) => {
            setConversations((current) =>
                sortConversations([
                    conversation,
                    ...current.filter((c) => c.id !== conversation.id),
                ]),
            );
        },
        [],
    );

    const chat = useCopilotChat({
        teamSlug,
        channel: 'page',
        onConversationSaved: upsertConversation,
        onQuota: setQuota,
    });

    const { load } = chat;

    useEffect(() => {
        if (props.initialConversationId) {
            void load(props.initialConversationId);
        }
    }, [props.initialConversationId, load]);

    // Keep the URL shareable/bookmarkable for the open thread.
    useEffect(() => {
        const url = new URL(window.location.href);

        if (chat.conversationId) {
            url.searchParams.set('c', String(chat.conversationId));
        } else {
            url.searchParams.delete('c');
        }

        window.history.replaceState(window.history.state, '', url);
    }, [chat.conversationId]);

    const removeConversation = async (conversation: CopilotConversation) => {
        if (
            !window.confirm(
                `¿Eliminar la conversación “${conversation.title}”?`,
            )
        ) {
            return;
        }

        await chat.remove(conversation.id);
        setConversations((current) =>
            current.filter((c) => c.id !== conversation.id),
        );
    };

    const catalog: CopilotCatalog = { ...props, conversations, quota };
    const engineLabel =
        props.engine.mode === 'llm'
            ? `IA generativa · ${props.engine.model ?? 'modelo configurado'}`
            : 'Respuestas desde datos';

    return (
        <>
            <Head title="SAM Copilot" />
            <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden md:grid-cols-[260px_minmax(0,1fr)]">
                <aside className="hidden min-h-0 flex-col border-r border-border bg-surface-1 md:flex">
                    <div className="p-3">
                        <button
                            type="button"
                            onClick={chat.reset}
                            className="flex w-full cursor-pointer items-center gap-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-sm font-medium text-fg-1 hover:bg-surface-3"
                        >
                            <MessageSquarePlus className="size-4 text-ai-accent" />
                            <span className="flex-1 text-left">
                                Nueva conversación
                            </span>
                        </button>
                    </div>
                    <div className="px-4 pt-1 pb-1.5 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                        Conversaciones
                    </div>
                    <nav className="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-2 pb-2">
                        {conversations.length === 0 && (
                            <p className="px-2 py-3 text-xs text-fg-3">
                                Aquí aparecerán tus conversaciones. Son
                                privadas: solo tú las ves.
                            </p>
                        )}
                        {conversations.map((conversation) => (
                            <div
                                key={conversation.id}
                                className={cn(
                                    'group relative rounded-md',
                                    conversation.id === chat.conversationId
                                        ? 'bg-primary/15'
                                        : 'hover:bg-surface-2',
                                )}
                            >
                                <button
                                    type="button"
                                    onClick={() =>
                                        void chat.load(conversation.id)
                                    }
                                    className="flex w-full cursor-pointer flex-col gap-0.5 px-2.5 py-2 pr-14 text-left"
                                >
                                    <span className="line-clamp-2 text-xs font-medium text-fg-1">
                                        {conversation.isPinned && (
                                            <Pin className="mr-1 inline size-3 text-ai-accent" />
                                        )}
                                        {conversation.title}
                                    </span>
                                    <span className="font-mono text-3xs text-fg-3">
                                        {timeAgo(conversation.lastMessageAt)}
                                    </span>
                                </button>
                                <div className="absolute top-1.5 right-1.5 hidden gap-0.5 group-focus-within:flex group-hover:flex">
                                    <button
                                        type="button"
                                        aria-label={
                                            conversation.isPinned
                                                ? 'Desfijar'
                                                : 'Fijar'
                                        }
                                        onClick={() =>
                                            void chat.togglePin(conversation)
                                        }
                                        className="grid size-6 cursor-pointer place-items-center rounded-sm text-fg-3 hover:bg-surface-3 hover:text-fg-1"
                                    >
                                        {conversation.isPinned ? (
                                            <PinOff className="size-3" />
                                        ) : (
                                            <Pin className="size-3" />
                                        )}
                                    </button>
                                    <button
                                        type="button"
                                        aria-label="Eliminar"
                                        onClick={() =>
                                            void removeConversation(
                                                conversation,
                                            )
                                        }
                                        className="grid size-6 cursor-pointer place-items-center rounded-sm text-fg-3 hover:bg-severity-critical/15 hover:text-severity-critical"
                                    >
                                        <Trash2 className="size-3" />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </nav>
                    <QuotaMeter quota={quota} />
                    {props.canViewUsage && (
                        <Link
                            href={`/${teamSlug}/copilot/usage`}
                            className="mx-3 mb-3 flex items-center gap-2 rounded-md border border-border px-3 py-2 text-xs text-fg-2 hover:bg-surface-2 hover:text-fg-1"
                        >
                            <BarChart3 className="size-3.5" /> Uso y consumo
                        </Link>
                    )}
                </aside>

                <section className="flex min-h-0 min-w-0 flex-col">
                    <header className="flex shrink-0 flex-wrap items-center gap-3 border-b border-border px-4 py-3 sm:px-6">
                        <div className="grid size-9 place-items-center rounded-md bg-ai-accent-bg text-ai-accent">
                            <Sparkles className="size-[18px]" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <h1 className="text-sm font-semibold text-fg-1">
                                SAM Copilot
                            </h1>
                            <p className="truncate font-mono text-3xs text-fg-3">
                                Conectado a {team?.name} · {engineLabel}
                            </p>
                        </div>
                        <div className="hidden items-center gap-2 rounded-full border border-border bg-surface-2 px-3 py-1 text-2xs text-fg-2 lg:flex">
                            <Plug className="size-3" />
                            {[
                                'Unidades',
                                'GPS',
                                'Telemetría',
                                'Media',
                                'Incidentes',
                                'Conductores',
                            ].map((source, index) => (
                                <span
                                    key={source}
                                    className="flex items-center gap-2"
                                >
                                    {index > 0 && (
                                        <span className="size-[3px] rounded-full bg-fg-3" />
                                    )}
                                    {source}
                                </span>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={chat.reset}
                            className="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-xs text-fg-2 hover:bg-surface-2 md:hidden"
                        >
                            <MessageSquarePlus className="size-3.5" /> Nueva
                        </button>
                    </header>
                    <CopilotChatPanel
                        chat={chat}
                        catalog={catalog}
                        userName={user?.name ?? ''}
                        teamName={team?.name ?? ''}
                    />
                </section>
            </div>
        </>
    );
}

function QuotaMeter({ quota }: { quota: CopilotQuota }) {
    if (quota.included === null) {
        return (
            <div className="mx-3 mb-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-3xs text-fg-3">
                <span className="font-mono text-xs font-semibold text-fg-1">
                    {quota.used}
                </span>{' '}
                consultas este mes
            </div>
        );
    }

    const percent = Math.min(100, quota.percent ?? 0);

    return (
        <div className="mx-3 mb-2 rounded-md border border-border bg-surface-2 px-3 py-2">
            <div className="flex items-baseline justify-between text-3xs text-fg-3">
                <span>Consultas del mes</span>
                <span className="font-mono text-fg-2 tabular-nums">
                    {quota.used} / {quota.included}
                </span>
            </div>
            <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-surface-3">
                <div
                    className={cn(
                        'h-full rounded-full',
                        percent >= 100
                            ? 'bg-severity-critical'
                            : percent >= 80
                              ? 'bg-severity-high'
                              : 'bg-ai-accent',
                    )}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

CopilotIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'SAM Copilot',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/copilot`
                : '/copilot',
        },
    ],
});
