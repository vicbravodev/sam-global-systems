import { formatNumber } from '@/lib/format';
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

/**
 * `tool` of the one `data-copilot-blocks` part a deterministic answer sends.
 * After an agent failure it is the COMPLETE card set of the turn (the agent
 * cards already shown are abandoned server-side), so it replaces them.
 */
export const DETERMINISTIC_TOOL = 'deterministic';

/**
 * Client identity of each card, assigned when it arrives
 * (`toolCallId:index`) and carried over to the stored copy. It is the React
 * key, so a card keeps its element (map, video) even if it moves.
 */
const blockKeys = new WeakMap<CopilotBlock, string>();

/** React key of a card: its client identity, or the index for history. */
export function blockKey(block: CopilotBlock, index: number): string {
    return blockKeys.get(block) ?? `${block.type}-${index}`;
}

function tagBlocks(blocks: CopilotBlock[], prefix: string): CopilotBlock[] {
    blocks.forEach((block, index) => {
        if (!blockKeys.has(block)) {
            blockKeys.set(block, `${prefix}:${index}`);
        }
    });

    return blocks;
}

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
                return draft.phase === 'finishing' && draft === state.draft
                    ? state
                    : { lastTextId, draft: { ...draft, phase: 'finishing' } };
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
                return draft === state.draft ? state : { lastTextId, draft };
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
        case 'data-copilot-blocks': {
            const incoming = tagBlocks(part.data.blocks, part.data.toolCallId);

            if (part.data.tool === DETERMINISTIC_TOOL) {
                // Fallback answer: its set replaces the abandoned agent
                // cards (matching ones keep their element) and its tools.
                return {
                    lastTextId,
                    draft: {
                        ...draft,
                        blocks: reconcileBlocks(draft.blocks, incoming),
                        activeTools: [],
                        pendingCards: [],
                        toolCount: 0,
                    },
                };
            }

            return {
                lastTextId,
                draft: {
                    ...draft,
                    blocks: [...draft.blocks, ...incoming],
                    pendingCards: (draft.pendingCards ?? []).filter(
                        (p) => p.toolCallId !== part.data.toolCallId,
                    ),
                },
            };
        }
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
            return draft.phase === 'finishing' && draft === state.draft
                ? state
                : { lastTextId, draft: { ...draft, phase: 'finishing' } };
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
 * Maps a new card list (the stored answer, or a fallback set) onto the cards
 * already on screen, by identity instead of index:
 * 1. a card with the same content anywhere keeps the object on screen
 *    (memoized cards, maps and videos see identical props);
 * 2. a changed card takes over the identity (React key) of the first unused
 *    on-screen card of the same type, so it updates in place, not remounts;
 * 3. anything else is new and gets its own identity.
 * On-screen cards left unmatched are dropped. Returns `previous` itself
 * when nothing changed.
 */
export function reconcileBlocks(
    previous: CopilotBlock[],
    next: CopilotBlock[],
): CopilotBlock[] {
    if (previous.length === 0) {
        return tagBlocks(next, 'stored');
    }

    const used = new Set<number>();
    const exact = next.map((block) => {
        const at = previous.findIndex(
            (old, i) =>
                !used.has(i) &&
                old.type === block.type &&
                sameBlock(old, block),
        );

        if (at === -1) {
            return null;
        }

        used.add(at);

        return previous[at];
    });

    const merged = next.map((block, index) => {
        const kept = exact[index];

        if (kept) {
            return kept;
        }

        const at = previous.findIndex(
            (old, i) => !used.has(i) && old.type === block.type,
        );
        const prior = at === -1 ? undefined : previous[at];
        const inherited = prior ? blockKeys.get(prior) : undefined;

        if (at !== -1) {
            used.add(at);
        }

        blockKeys.set(
            block,
            inherited ?? blockKeys.get(block) ?? `stored:${index}`,
        );

        return block;
    });

    const unchanged =
        merged.length === previous.length &&
        merged.every((block, i) => block === previous[i]);

    return unchanged ? previous : merged;
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
    const at = messages.findIndex(
        (m) => m.clientKey !== undefined && m.clientKey === next.clientKey,
    );

    if (at === -1 || messages[at] === next) {
        return messages;
    }

    const updated = messages.slice();
    updated[at] = next;

    return updated;
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
        return `${formatNumber(seconds, {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        })} s`;
    }

    return `${Math.round(seconds)} s`;
}
