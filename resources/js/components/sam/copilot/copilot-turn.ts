import type {
    CopilotBlock,
    CopilotMessage,
    CopilotSendHints,
} from '@/types/copilot';
import type { CopilotStreamPart } from './copilot-stream';
import { appendTextDelta, toolStatusLabel } from './copilot-stream';

/**
 * Pure state of one live Copilot turn: the optimistic question, the draft
 * answer and how each stream part moves it. No React here, so the hook
 * only schedules renders and the merge rules stay testable.
 */

/** The agent's hidden tool: it only feeds the follow-up chips. */
export const HIDDEN_TOOL = 'suggest_followups';

let keySeq = 0;

export function nextTurnKey(): string {
    keySeq += 1;

    return `turn-${Date.now().toString(36)}-${keySeq}`;
}

export interface LiveTurn {
    question: CopilotMessage;
    draft: CopilotMessage;
}

/**
 * The question and the empty answer draft, created together at send time so
 * the answer bubble mounts once and never swaps shape. The draft starts with
 * EMPTY content (it must never echo the question while thinking).
 */
export function createTurn(
    text: string,
    hints: CopilotSendHints,
    key: string,
    nowIso: string,
    nowMs: number,
): LiveTurn {
    const base = {
        intent: null,
        intentLabel: null,
        blocks: [],
        tools: [],
        sources: [],
        followups: [],
        usage: null,
        feedback: null,
        createdAt: nowIso,
    } satisfies Partial<CopilotMessage>;

    return {
        question: {
            ...base,
            // Negative ids are client-only; the key is what React sees.
            id: -nowMs - 1,
            clientKey: `${key}:q`,
            role: 'user',
            content: text,
            // The unit chip is there from the first frame, not at the swap.
            context: hints.assetId ? { asset_id: hints.assetId } : null,
            pending: true,
        },
        draft: {
            ...base,
            id: -nowMs - 2,
            clientKey: `${key}:a`,
            role: 'assistant',
            content: '',
            context: null,
            streaming: true,
            phase: 'thinking',
            startedAt: nowMs,
            toolCount: 0,
            activeTools: [],
            pendingCards: [],
        },
    };
}

export interface DraftState {
    draft: CopilotMessage;
    /** Part id of the last text delta: a new id is a new agent step. */
    lastTextId: string | null;
}

/** Placeholders whose tool finished without cards go on the next part. */
function dropFinishedPlaceholders(draft: CopilotMessage): CopilotMessage {
    const pending = draft.pendingCards ?? [];

    return pending.some((p) => p.finished)
        ? { ...draft, pendingCards: pending.filter((p) => !p.finished) }
        : draft;
}

/**
 * Applies one stream part to the draft. Returns the same object when the
 * part changes nothing visible, so callers can skip a render.
 * `data-copilot-message`, `data-copilot-conversation` and `error` are the
 * hook's business (they touch more than the draft).
 */
export function reduceDraft(
    state: DraftState,
    part: CopilotStreamPart,
): DraftState {
    const { lastTextId } = state;
    let draft = state.draft;

    // Tool cards arrive right after their tool-output part; any other part
    // means a finished tool produced none, so its placeholder goes.
    if (part.type !== 'data-copilot-blocks') {
        draft = dropFinishedPlaceholders(draft);
    }

    switch (part.type) {
        case 'tool-input-available': {
            if (part.toolName === HIDDEN_TOOL) {
                return { lastTextId, draft: { ...draft, phase: 'finishing' } };
            }

            return {
                lastTextId,
                draft: {
                    ...draft,
                    phase: 'tool',
                    toolCount: (draft.toolCount ?? 0) + 1,
                    activeTools: [
                        ...(draft.activeTools ?? []),
                        {
                            toolCallId: part.toolCallId,
                            tool: part.toolName,
                            label: toolStatusLabel(
                                part.toolName,
                                part.input ?? {},
                            ),
                        },
                    ],
                    pendingCards: [
                        ...(draft.pendingCards ?? []),
                        {
                            toolCallId: part.toolCallId,
                            tool: part.toolName,
                            finished: false,
                        },
                    ],
                },
            };
        }
        case 'tool-output-available':
        case 'tool-output-error': {
            const active = draft.activeTools ?? [];

            if (!active.some((t) => t.toolCallId === part.toolCallId)) {
                return { lastTextId, draft };
            }

            const remaining = active.filter(
                (t) => t.toolCallId !== part.toolCallId,
            );

            return {
                lastTextId,
                draft: {
                    ...draft,
                    activeTools: remaining,
                    // The model reads the results before it writes again.
                    phase: remaining.length > 0 ? 'tool' : 'thinking',
                    pendingCards:
                        part.type === 'tool-output-error'
                            ? (draft.pendingCards ?? []).filter(
                                  (p) => p.toolCallId !== part.toolCallId,
                              )
                            : (draft.pendingCards ?? []).map((p) =>
                                  p.toolCallId === part.toolCallId
                                      ? { ...p, finished: true }
                                      : p,
                              ),
                },
            };
        }
        case 'data-copilot-blocks':
            return {
                lastTextId,
                draft: {
                    ...draft,
                    blocks: [...draft.blocks, ...part.data.blocks],
                    pendingCards: (draft.pendingCards ?? []).filter(
                        (p) => p.toolCallId !== part.data.toolCallId,
                    ),
                },
            };
        case 'text-start':
            return draft.phase === 'writing'
                ? { lastTextId, draft }
                : { lastTextId, draft: { ...draft, phase: 'writing' } };
        case 'text-delta':
            return {
                lastTextId: part.id,
                draft: {
                    ...draft,
                    phase: 'writing',
                    content: appendTextDelta(
                        draft.content,
                        lastTextId,
                        part.id,
                        part.delta,
                    ).content,
                },
            };
        case 'text-end':
            // No more text in this step: suggestions + storing come next,
            // unless the agent opens another step with tools.
            return { lastTextId, draft: { ...draft, phase: 'finishing' } };
        case 'data-copilot-followups':
            return {
                lastTextId,
                draft: { ...draft, followups: part.data.questions },
            };
        default:
            return draft === state.draft ? state : { lastTextId, draft };
    }
}

function sameBlock(a: CopilotBlock, b: CopilotBlock): boolean {
    return a === b || JSON.stringify(a) === JSON.stringify(b);
}

/**
 * Keeps the block objects already on screen when the stored answer brings
 * the same card (same index, same content): memoized cards, maps and videos
 * see the same props and do not re-run. A changed card is replaced at its
 * index (same React key, so it updates without remounting); extra cards
 * are appended.
 */
export function reconcileBlocks(
    previous: CopilotBlock[],
    next: CopilotBlock[],
): CopilotBlock[] {
    if (previous.length === 0) {
        return next;
    }

    let changed = previous.length !== next.length;
    const merged = next.map((block, index) => {
        const old = previous[index];

        if (old && old.type === block.type && sameBlock(old, block)) {
            return old;
        }

        changed = true;

        return block;
    });

    return changed ? merged : previous;
}

function elapsedSince(
    draft: CopilotMessage,
    nowMs: number,
): number | undefined {
    return draft.startedAt !== undefined
        ? Math.max(0, nowMs - draft.startedAt)
        : undefined;
}

/** The stored answer, merged into the draft's slot (same key, same cards). */
export function settleAnswer(
    draft: CopilotMessage,
    answer: CopilotMessage,
    nowMs: number,
): CopilotMessage {
    return {
        ...answer,
        clientKey: draft.clientKey,
        content: answer.content,
        blocks: reconcileBlocks(draft.blocks, answer.blocks),
        followups:
            answer.followups.length > 0 ? answer.followups : draft.followups,
        streaming: false,
        phase: 'done',
        startedAt: draft.startedAt,
        elapsedMs: elapsedSince(draft, nowMs),
        // Deterministic turns stream no tool parts: fall back to the trace.
        toolCount: draft.toolCount ? draft.toolCount : undefined,
        activeTools: [],
        pendingCards: [],
    };
}

/** The stream ended without the stored message (stop, drop, error). */
export function settleInterrupted(
    draft: CopilotMessage,
    nowMs: number,
): CopilotMessage {
    return {
        ...draft,
        streaming: false,
        partial: true,
        phase: 'done',
        elapsedMs: elapsedSince(draft, nowMs),
        activeTools: [],
        pendingCards: [],
    };
}

/** Replaces the message that has `next.clientKey`, keeping its position. */
export function replaceByKey(
    messages: CopilotMessage[],
    next: CopilotMessage,
): CopilotMessage[] {
    let found = false;
    const updated = messages.map((m) => {
        if (m.clientKey !== undefined && m.clientKey === next.clientKey) {
            found = true;

            return next;
        }

        return m;
    });

    return found ? updated : messages;
}

/**
 * Folds the server's copy of the turn into the two live entries in place:
 * same positions, same keys; the question takes the stored fields.
 */
export function mergeStoredTurn(
    messages: CopilotMessage[],
    questionKey: string,
    storedQuestion: CopilotMessage,
    settledAnswer: CopilotMessage,
): CopilotMessage[] {
    let hasQuestion = false;
    let hasAnswer = false;
    const merged = messages.map((m) => {
        if (m.clientKey === questionKey) {
            hasQuestion = true;

            return {
                ...storedQuestion,
                clientKey: questionKey,
                // Keep the chip sent with the question if the server's
                // context does not carry it.
                context: storedQuestion.context ?? m.context,
                pending: false,
            };
        }

        if (m.clientKey === settledAnswer.clientKey) {
            hasAnswer = true;

            return settledAnswer;
        }

        return m;
    });

    return [
        ...merged,
        ...(hasQuestion ? [] : [{ ...storedQuestion, clientKey: questionKey }]),
        ...(hasAnswer ? [] : [settledAnswer]),
    ];
}

/** Drops both live entries of a turn the server rejected before answering. */
export function dropTurn(
    messages: CopilotMessage[],
    turn: LiveTurn,
): CopilotMessage[] {
    return messages.filter(
        (m) =>
            m.clientKey !== turn.question.clientKey &&
            m.clientKey !== turn.draft.clientKey,
    );
}

/** "Listo · 2,1 s" / "22 s". */
export function formatElapsed(ms: number): string {
    const seconds = ms / 1000;

    if (seconds < 10) {
        return `${seconds.toLocaleString('es-MX', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        })} s`;
    }

    return `${Math.round(seconds)} s`;
}
