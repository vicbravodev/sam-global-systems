import type { SharedPageProps } from '@inertiajs/core';
import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { CopilotChatPanel } from '@/components/sam/copilot/copilot-chat-panel';
import { CopilotConversationSidebar } from '@/components/sam/copilot/copilot-conversation-sidebar';
import { CopilotPageHeader } from '@/components/sam/copilot/copilot-page-header';
import { useCopilotChat } from '@/components/sam/copilot/use-copilot-chat';
import copilotRoutes from '@/routes/copilot';
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

export default function CopilotIndex(props: PageProps) {
    const page = usePage();
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

    // Stable between stream frames: the panel memoizes on it.
    const catalog: CopilotCatalog = useMemo(
        () => ({ ...props, conversations, quota }),
        [props, conversations, quota],
    );
    const engineLabel =
        props.engine.mode === 'llm'
            ? `IA generativa · ${props.engine.model ?? 'modelo configurado'}`
            : 'Respuestas desde datos';

    return (
        <>
            <Head title="SAM Copilot" />
            <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden md:grid-cols-[260px_minmax(0,1fr)]">
                <CopilotConversationSidebar
                    conversations={conversations}
                    activeConversationId={chat.conversationId}
                    quota={quota}
                    canViewUsage={props.canViewUsage}
                    teamSlug={teamSlug}
                    onNew={chat.reset}
                    onLoad={(conversation) => void chat.load(conversation.id)}
                    onTogglePin={(conversation) =>
                        void chat.togglePin(conversation)
                    }
                    onRemove={(conversation) =>
                        void removeConversation(conversation)
                    }
                />

                <section className="flex min-h-0 min-w-0 flex-col">
                    <CopilotPageHeader
                        teamName={team?.name}
                        engineLabel={engineLabel}
                        onNew={chat.reset}
                    />
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

CopilotIndex.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'SAM Copilot',
            href: props.currentTeam
                ? copilotRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
