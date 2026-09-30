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
import { readCopilotStream } from './copilot-stream';
import type { DraftState } from './copilot-turn';
import {
    createTurn,
    dropTurn,
    mergeStoredTurn,
    nextTurnKey,
    reduceDraft,
    replaceByKey,
    settleAnswer,
    settleInterrupted,
} from './copilot-turn';

interface Options {
    teamSlug: string;
    channel: CopilotChannel;
    onConversationSaved?: (conversation: CopilotConversation) => void;
    onQuota?: (quota: CopilotQuota) => void;
}

/**
 * Client state of one Copilot thread: messages, the in-flight question and
 * the calls to the session-authenticated Copilot routes.
 *
 * A turn sent from here keeps one stable `clientKey` per entry from the
 * optimistic question / empty draft to the stored messages, so the bubbles
 * never remount. Stream parts are folded into the draft outside React and
 * flushed once per animation frame.
 */
export function useCopilotChat({
    teamSlug,
    channel,
    onConversationSaved,
    onQuota,
}: Options) {
    const [conversationId, setConversationIdState] = useState<number | null>(
        null,
    );
    const [messages, setMessages] = useState<CopilotMessage[]>([]);
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [canRetry, setCanRetry] = useState(false);
    const [lastHints, setLastHints] = useState<CopilotSendHints>({});
    /** Bumps on reset/load: a new thread (scopes pinned-unit overrides). */
    const [thread, setThread] = useState(0);

    const abortRef = useRef<AbortController | null>(null);
    const loadAbortRef = useRef<AbortController | null>(null);
    const conversationRef = useRef<number | null>(null);
    /** Synchronous double-send guard (`busy` lags one render behind). */
    const inFlightRef = useRef(false);
    /** Token of the live turn: reset/load bump it so a stale turn goes quiet. */
    const turnRef = useRef(0);
    const lastRequestRef = useRef<{
        content: string;
        hints: CopilotSendHints;
    } | null>(null);

    const base = `/${teamSlug}/copilot`;

    const setConversationId = useCallback((id: number | null) => {
        conversationRef.current = id;
        setConversationIdState(id);
    }, []);

    /** Silences the live turn: its late parts and `finally` touch nothing. */
    const abandonTurn = useCallback(() => {
        turnRef.current += 1;
        inFlightRef.current = false;
        abortRef.current?.abort();
        abortRef.current = null;
        setBusy(false);
    }, []);

    const send = useCallback(
        async (content: string, hints: CopilotSendHints = {}) => {
            const text = content.trim();

            if (text.length < 2 || inFlightRef.current) {
                return;
            }

            inFlightRef.current = true;
            turnRef.current += 1;
            const token = turnRef.current;
            const isCurrent = () => turnRef.current === token;
            lastRequestRef.current = { content: text, hints };

            setError(null);
            setCanRetry(false);
            setBusy(true);
            setLastHints(hints);

            // Question and empty draft mount together: one bubble per role
            // for the whole turn, no "thinking" card swapped out later.
            const turn = createTurn(
                text,
                hints,
                nextTurnKey(),
                new Date().toISOString(),
                Date.now(),
            );
            const questionKey = turn.question.clientKey as string;
            setMessages((current) => [...current, turn.question, turn.draft]);

            const controller = new AbortController();
            abortRef.current = controller;

            let state: DraftState = { draft: turn.draft, lastTextId: null };
            let completed = false;
            let rejected = false;
            let received = false;

            // Parts land in `state`; React sees them at most once per frame.
            let frame = 0;
            const commit = () => {
                frame = 0;

                if (isCurrent()) {
                    const draft = state.draft;
                    setMessages((current) => replaceByKey(current, draft));
                }
            };
            const schedule = () => {
                if (frame === 0) {
                    frame = requestAnimationFrame(commit);
                }
            };
            const cancelFrame = () => {
                if (frame !== 0) {
                    cancelAnimationFrame(frame);
                    frame = 0;
                }
            };
            const reject = (message: string) => {
                rejected = true;
                cancelFrame();
                setError(message);
                setCanRetry(true);
                setMessages((current) => dropTurn(current, turn));
            };

            try {
                const response = await postStream(
                    `${base}/stream`,
                    {
                        content: text,
                        conversation_id: conversationRef.current,
                        asset_id: hints.assetId ?? null,
                        intent: hints.intent ?? null,
                        channel,
                    },
                    controller.signal,
                );

                if (!isCurrent()) {
                    return;
                }

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
                    reject(message);

                    return;
                }

                for await (const part of readCopilotStream(response.body)) {
                    if (!isCurrent()) {
                        break;
                    }

                    received = true;

                    switch (part.type) {
                        case 'data-copilot-conversation':
                            // Early, before any model work: the thread survives a stop.
                            setConversationId(part.data.id);
                            break;
                        case 'data-copilot-message': {
                            // Authoritative: merged into the live entries in place.
                            completed = true;
                            cancelFrame();
                            const answer = settleAnswer(
                                state.draft,
                                part.data.answer,
                                Date.now(),
                            );
                            state = { ...state, draft: answer };
                            setConversationId(part.data.conversation.id);
                            setMessages((current) =>
                                mergeStoredTurn(
                                    current,
                                    questionKey,
                                    part.data.question,
                                    answer,
                                ),
                            );
                            onConversationSaved?.(part.data.conversation);
                            onQuota?.(part.data.quota);
                            break;
                        }
                        case 'error':
                            setError(part.errorText);

                            // After the stored message the answer is final.
                            if (!completed) {
                                completed = true;
                                cancelFrame();
                                state = {
                                    ...state,
                                    draft: settleInterrupted(
                                        state.draft,
                                        Date.now(),
                                    ),
                                };
                                commit();
                            }

                            break;
                        default: {
                            if (completed) {
                                break;
                            }

                            const next = reduceDraft(state, part);

                            if (next !== state) {
                                state = next;
                                schedule();
                            }
                        }
                    }
                }
            } catch (caught) {
                if ((caught as Error).name !== 'AbortError' && isCurrent()) {
                    const offline =
                        'Sin conexión con SAM. Revisa tu red e intenta de nuevo.';

                    if (received) {
                        setError(offline);
                        setCanRetry(true);
                    } else {
                        reject(offline);
                    }
                }
            } finally {
                cancelFrame();

                if (isCurrent()) {
                    if (!completed && !rejected) {
                        // Ended without the stored message (stop, drop, server
                        // omitted it). The server stored the question, so it
                        // stays; the draft settles as partial in place.
                        const draft = settleInterrupted(
                            state.draft,
                            Date.now(),
                        );
                        setMessages((current) =>
                            replaceByKey(current, draft).map((m) =>
                                m.clientKey === questionKey
                                    ? { ...m, pending: false }
                                    : m,
                            ),
                        );
                    }

                    setBusy(false);
                    inFlightRef.current = false;
                }

                if (abortRef.current === controller) {
                    abortRef.current = null;
                }
            }
        },
        [base, channel, onConversationSaved, onQuota, setConversationId],
    );

    const stop = useCallback(() => abortRef.current?.abort(), []);

    /** Re-sends the last question (after a request or network failure). */
    const retry = useCallback(() => {
        const last = lastRequestRef.current;

        if (last) {
            void send(last.content, last.hints);
        }
    }, [send]);

    const load = useCallback(
        async (id: number) => {
            abandonTurn();
            loadAbortRef.current?.abort();
            const controller = new AbortController();
            loadAbortRef.current = controller;

            setThread((t) => t + 1);
            setMessages([]);
            setLoading(true);
            setError(null);
            setCanRetry(false);

            try {
                const response = await fetch(`${base}/conversations/${id}`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    setError('No encontré esa conversación.');

                    return;
                }

                const data = (await response.json()) as {
                    conversation: CopilotConversation;
                    messages: CopilotMessage[];
                };

                if (loadAbortRef.current !== controller) {
                    return;
                }

                setConversationId(data.conversation.id);
                setMessages(data.messages);
            } catch (caught) {
                if ((caught as Error).name !== 'AbortError') {
                    setError('No pude abrir la conversación.');
                }
            } finally {
                if (loadAbortRef.current === controller) {
                    loadAbortRef.current = null;
                    setLoading(false);
                }
            }
        },
        [abandonTurn, base, setConversationId],
    );

    const reset = useCallback(() => {
        abandonTurn();
        loadAbortRef.current?.abort();
        loadAbortRef.current = null;
        setThread((t) => t + 1);
        setConversationId(null);
        setMessages([]);
        setError(null);
        setCanRetry(false);
        setLoading(false);
    }, [abandonTurn, setConversationId]);

    const dismissError = useCallback(() => {
        setError(null);
        setCanRetry(false);
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

            if (id === conversationRef.current) {
                reset();
            }
        },
        [base, reset],
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
        canRetry,
        lastHints,
        thread,
        send,
        stop,
        retry,
        load,
        reset,
        rate,
        remove,
        togglePin,
        dismissError,
    };
}
