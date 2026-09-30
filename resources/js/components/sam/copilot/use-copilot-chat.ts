import { useCallback, useRef, useState } from 'react';
import {
    deleteJson,
    patchJson,
    postStream,
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
import {
    appendTextDelta,
    readCopilotStream,
    toolStatusLabel,
} from './copilot-stream';

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

            const draftId = optimistic.id - 1;
            let completed = false;
            let rejected = false;
            let draftCreated = false;
            const patch = (fn: (m: CopilotMessage) => CopilotMessage) =>
                setMessages((current) =>
                    current.map((m) => (m.id === draftId ? fn(m) : m)),
                );
            const dropOptimistic = () => {
                rejected = true;
                setMessages((current) =>
                    current.filter((m) => m.id !== optimistic.id),
                );
            };

            try {
                const response = await postStream(
                    `${base}/stream`,
                    {
                        content: text,
                        conversation_id: conversationId,
                        asset_id: hints.assetId ?? null,
                        intent: hints.intent ?? null,
                        channel,
                    },
                    controller.signal,
                );
                const isStream = (
                    response.headers.get('content-type') ?? ''
                ).includes('text/event-stream');
                const sessionLost =
                    response.redirected ||
                    response.status === 401 ||
                    response.status === 419;

                if (
                    sessionLost ||
                    !response.ok ||
                    !response.body ||
                    !isStream
                ) {
                    const message = sessionLost
                        ? 'Tu sesión expiró. Recarga la página e inténtalo de nuevo.'
                        : response.status === 429
                          ? 'Demasiadas consultas seguidas. Espera unos segundos y vuelve a intentar.'
                          : response.status === 403
                            ? 'Tu rol no tiene acceso a SAM Copilot o el módulo está desactivado para tu empresa.'
                            : !response.ok
                              ? ((await readErrorMessage(response)) ??
                                'No pude responder. Intenta de nuevo.')
                              : 'Tu sesión expiró. Recarga la página e inténtalo de nuevo.';
                    setError(message);
                    dropOptimistic();

                    return;
                }

                const draft: CopilotMessage = {
                    ...optimistic,
                    id: draftId,
                    role: 'assistant',
                    // Never echo the question while the answer is thinking.
                    content: '',
                    context: null,
                    pending: false,
                    streaming: true,
                    activeTools: [],
                    followups: [],
                };
                draftCreated = true;
                setMessages((current) => [...current, draft]);

                // Part id of the last text-delta: a new id is a new agent step.
                let lastTextId: string | null = null;

                for await (const part of readCopilotStream(response.body)) {
                    switch (part.type) {
                        case 'tool-input-available':
                            if (part.toolName === 'suggest_followups') {
                                break;
                            }

                            patch((m) => ({
                                ...m,
                                activeTools: [
                                    ...(m.activeTools ?? []),
                                    {
                                        toolCallId: part.toolCallId,
                                        label: toolStatusLabel(
                                            part.toolName,
                                            part.input ?? {},
                                        ),
                                    },
                                ],
                            }));
                            break;
                        case 'tool-output-available':
                        case 'tool-output-error':
                            patch((m) => ({
                                ...m,
                                activeTools: (m.activeTools ?? []).filter(
                                    (t) => t.toolCallId !== part.toolCallId,
                                ),
                            }));
                            break;
                        case 'data-copilot-blocks':
                            patch((m) => ({
                                ...m,
                                blocks: [...m.blocks, ...part.data.blocks],
                            }));
                            break;
                        case 'text-delta': {
                            const previousTextId = lastTextId;
                            lastTextId = part.id;
                            patch((m) => ({
                                ...m,
                                content: appendTextDelta(
                                    m.content,
                                    previousTextId,
                                    part.id,
                                    part.delta,
                                ).content,
                            }));
                            break;
                        }
                        case 'data-copilot-conversation':
                            // Early, before any model work: the thread survives a stop.
                            setConversationId(part.data.id);
                            break;
                        case 'data-copilot-followups':
                            patch((m) => ({
                                ...m,
                                followups: part.data.questions,
                            }));
                            break;
                        case 'data-copilot-message':
                            // Authoritative: replaces the optimistic question and the draft.
                            completed = true;
                            setConversationId(part.data.conversation.id);
                            setMessages((current) => [
                                ...current.filter(
                                    (m) =>
                                        m.id !== optimistic.id &&
                                        m.id !== draftId,
                                ),
                                part.data.question,
                                part.data.answer,
                            ]);
                            onConversationSaved?.(part.data.conversation);
                            onQuota?.(part.data.quota);
                            break;
                        case 'error':
                            setError(part.errorText);
                            patch((m) => ({
                                ...m,
                                streaming: false,
                                partial: m.content !== '',
                                activeTools: [],
                            }));
                            break;
                    }
                }
            } catch (caught) {
                if ((caught as Error).name !== 'AbortError') {
                    setError(
                        'Sin conexión con SAM. Revisa tu red e intenta de nuevo.',
                    );
                    dropOptimistic();
                }
            } finally {
                if (!completed && !rejected) {
                    // The stream ended without the final message (stop, drop,
                    // server omitted it). The server stored the question, so it
                    // stays visible; only the client-side flags are settled.
                    setMessages((current) => {
                        const settled = current.map((m) =>
                            m.id === optimistic.id
                                ? { ...m, pending: false }
                                : m.id === draftId
                                  ? {
                                        ...m,
                                        streaming: false,
                                        partial: true,
                                        activeTools: [],
                                    }
                                  : m,
                        );

                        // Stopped before the answer started: leave an inline note.
                        return draftCreated
                            ? settled
                            : [
                                  ...settled,
                                  {
                                      ...optimistic,
                                      id: draftId,
                                      role: 'assistant' as const,
                                      content: 'Se detuvo antes de responder.',
                                      pending: false,
                                      partial: true,
                                  },
                              ];
                    });
                }

                setBusy(false);
                abortRef.current = null;
            }
        },
        [base, busy, channel, conversationId, onConversationSaved, onQuota],
    );

    const stop = useCallback(() => abortRef.current?.abort(), []);

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
        stop,
        load,
        reset,
        rate,
        remove,
        togglePin,
        dismissError: () => setError(null),
    };
}
