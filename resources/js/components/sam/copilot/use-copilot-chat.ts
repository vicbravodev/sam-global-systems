import { useCallback, useRef, useState } from 'react';
import {
    deleteJson,
    patchJson,
    postJson,
    putJson,
    readErrorMessage,
} from '@/lib/sam-fetch';
import type {
    CopilotChannel,
    CopilotConversation,
    CopilotMessage,
    CopilotQuota,
    CopilotSendHints,
} from '@/types/copilot';

interface SendResponse {
    conversation: CopilotConversation;
    question: CopilotMessage;
    answer: CopilotMessage;
    quota: CopilotQuota;
}

interface Options {
    teamSlug: string;
    channel: CopilotChannel;
    onConversationSaved?: (conversation: CopilotConversation) => void;
    onQuota?: (quota: CopilotQuota) => void;
}

/**
 * Client state of one Copilot thread: messages, the in-flight question and
 * the calls to the session-authenticated Copilot routes.
 */
export function useCopilotChat({
    teamSlug,
    channel,
    onConversationSaved,
    onQuota,
}: Options) {
    const [conversationId, setConversationId] = useState<number | null>(null);
    const [messages, setMessages] = useState<CopilotMessage[]>([]);
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [lastHints, setLastHints] = useState<CopilotSendHints>({});
    const abortRef = useRef<AbortController | null>(null);

    const base = `/${teamSlug}/copilot`;

    const send = useCallback(
        async (content: string, hints: CopilotSendHints = {}) => {
            const text = content.trim();

            if (text.length < 2 || busy) {
                return;
            }

            setError(null);
            setBusy(true);
            setLastHints(hints);

            const optimistic: CopilotMessage = {
                id: -Date.now(),
                role: 'user',
                content: text,
                intent: null,
                intentLabel: null,
                blocks: [],
                tools: [],
                sources: [],
                context: null,
                followups: [],
                usage: null,
                feedback: null,
                createdAt: new Date().toISOString(),
                pending: true,
            };

            setMessages((current) => [...current, optimistic]);

            const controller = new AbortController();
            abortRef.current = controller;

            try {
                const response = await postJson(
                    `${base}/messages`,
                    {
                        content: text,
                        conversation_id: conversationId,
                        asset_id: hints.assetId ?? null,
                        intent: hints.intent ?? null,
                        channel,
                    },
                    controller.signal,
                );

                if (!response.ok) {
                    const message =
                        response.status === 429
                            ? 'Demasiadas consultas seguidas. Espera unos segundos y vuelve a intentar.'
                            : response.status === 403
                              ? 'Tu rol no tiene acceso a SAM Copilot o el módulo está desactivado para tu empresa.'
                              : ((await readErrorMessage(response)) ??
                                'No pude responder. Intenta de nuevo.');
                    setError(message);
                    setMessages((current) =>
                        current.filter((m) => m.id !== optimistic.id),
                    );

                    return;
                }

                const data = (await response.json()) as SendResponse;

                setConversationId(data.conversation.id);
                setMessages((current) => [
                    ...current.filter((m) => m.id !== optimistic.id),
                    data.question,
                    data.answer,
                ]);
                onConversationSaved?.(data.conversation);
                onQuota?.(data.quota);
            } catch (caught) {
                if ((caught as Error).name !== 'AbortError') {
                    setError(
                        'Sin conexión con SAM. Revisa tu red e intenta de nuevo.',
                    );
                    setMessages((current) =>
                        current.filter((m) => m.id !== optimistic.id),
                    );
                }
            } finally {
                setBusy(false);
                abortRef.current = null;
            }
        },
        [base, busy, channel, conversationId, onConversationSaved, onQuota],
    );

    const load = useCallback(
        async (id: number) => {
            abortRef.current?.abort();
            setLoading(true);
            setError(null);

            try {
                const response = await fetch(`${base}/conversations/${id}`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    setError('No encontré esa conversación.');

                    return;
                }

                const data = (await response.json()) as {
                    conversation: CopilotConversation;
                    messages: CopilotMessage[];
                };
                setConversationId(data.conversation.id);
                setMessages(data.messages);
            } finally {
                setLoading(false);
            }
        },
        [base],
    );

    const reset = useCallback(() => {
        abortRef.current?.abort();
        setConversationId(null);
        setMessages([]);
        setError(null);
        setBusy(false);
    }, []);

    const rate = useCallback(
        async (messageId: number, rating: -1 | 1 | null) => {
            setMessages((current) =>
                current.map((m) =>
                    m.id === messageId ? { ...m, feedback: rating } : m,
                ),
            );
            await putJson(`${base}/messages/${messageId}/feedback`, {
                rating,
            });
        },
        [base],
    );

    const remove = useCallback(
        async (id: number) => {
            await deleteJson(`${base}/conversations/${id}`);

            if (id === conversationId) {
                reset();
            }
        },
        [base, conversationId, reset],
    );

    const togglePin = useCallback(
        async (conversation: CopilotConversation) => {
            const response = await patchJson(
                `${base}/conversations/${conversation.id}`,
                { is_pinned: !conversation.isPinned },
            );

            if (response.ok) {
                const data = (await response.json()) as {
                    conversation: CopilotConversation;
                };
                onConversationSaved?.(data.conversation);
            }
        },
        [base, onConversationSaved],
    );

    return {
        conversationId,
        messages,
        busy,
        loading,
        error,
        lastHints,
        send,
        load,
        reset,
        rate,
        remove,
        togglePin,
        dismissError: () => setError(null),
    };
}
