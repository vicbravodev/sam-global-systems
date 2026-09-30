import type {
    CopilotBlock,
    CopilotConversation,
    CopilotMessage,
    CopilotQuota,
} from '@/types/copilot';

export type CopilotStreamPart =
    | { type: 'text-delta'; id: string; delta: string }
    | {
          type: 'tool-input-available';
          toolCallId: string;
          toolName: string;
          input: Record<string, unknown>;
      }
    | { type: 'tool-output-available'; toolCallId: string }
    | { type: 'tool-output-error'; toolCallId: string }
    | {
          type: 'data-copilot-blocks';
          data: {
              toolCallId: string;
              tool: string;
              label: string;
              blocks: CopilotBlock[];
          };
      }
    | { type: 'data-copilot-conversation'; data: { id: number } }
    | { type: 'data-copilot-followups'; data: { questions: string[] } }
    | {
          type: 'data-copilot-message';
          data: {
              answer: CopilotMessage;
              question: CopilotMessage;
              conversation: CopilotConversation;
              quota: CopilotQuota;
          };
      }
    | { type: 'error'; errorText: string };

const KNOWN_TYPES: ReadonlySet<string> = new Set([
    'text-delta',
    'tool-input-available',
    'tool-output-available',
    'tool-output-error',
    'data-copilot-blocks',
    'data-copilot-conversation',
    'data-copilot-followups',
    'data-copilot-message',
    'error',
]);

function parsePart(payload: string): CopilotStreamPart | null {
    try {
        const parsed: unknown = JSON.parse(payload);

        if (
            typeof parsed === 'object' &&
            parsed !== null &&
            'type' in parsed &&
            typeof parsed.type === 'string' &&
            KNOWN_TYPES.has(parsed.type)
        ) {
            return parsed as CopilotStreamPart;
        }
    } catch {
        // A malformed frame is skipped; the final message part carries the persisted answer.
    }

    return null;
}

/**
 * Reads the Vercel UI message stream the Copilot endpoint emits: one JSON
 * part per `data:` line, frames separated by a blank line, `[DONE]` last.
 * Part types the UI does not use (start, finish, text-start...) are dropped.
 */
/**
 * Appends a `text-delta` to the draft answer. Each agent step streams its
 * text under a new part `id`; a new id starts a new paragraph so the steps
 * never run together ("…la unidad.Está en ruta.").
 */
export function appendTextDelta(
    content: string,
    lastId: string | null,
    id: string,
    delta: string,
): { content: string; lastId: string } {
    const separator =
        lastId !== null && lastId !== id && content !== '' ? '\n\n' : '';

    return { content: content + separator + delta, lastId: id };
}

export async function* readCopilotStream(
    body: ReadableStream<Uint8Array>,
): AsyncGenerator<CopilotStreamPart> {
    const reader = body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    try {
        while (true) {
            const { value, done } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });
            buffer = buffer.replace(/\r\n/g, '\n');
            let boundary = buffer.indexOf('\n\n');

            while (boundary !== -1) {
                const frame = buffer.slice(0, boundary).trim();
                buffer = buffer.slice(boundary + 2);
                boundary = buffer.indexOf('\n\n');

                if (!frame.startsWith('data:')) {
                    continue;
                }

                const payload = frame.slice(5).trim();

                if (payload === '[DONE]') {
                    return;
                }

                const part = parsePart(payload);

                if (part) {
                    yield part;
                }
            }
        }
    } finally {
        reader.releaseLock();
    }
}

/** Spanish status line for a running tool ("Consultando combustible de T555…"). */
export function toolStatusLabel(
    toolName: string,
    input: Record<string, unknown>,
): string {
    const unit =
        typeof input.asset_code === 'string' ? ` de ${input.asset_code}` : '';
    const labels: Record<string, string> = {
        asset_summary: `Revisando la ficha${unit}`,
        asset_location: `Ubicando${unit || ' la unidad'}`,
        asset_engine: `Leyendo motor${unit}`,
        asset_fuel: `Consultando combustible${unit}`,
        asset_media: `Buscando cámaras${unit}`,
        asset_activity: `Revisando actividad${unit}`,
        asset_timeline: `Armando la línea de tiempo${unit}`,
        panic_kpis: 'Revisando botones de pánico',
        open_incidents: 'Revisando incidentes abiertos',
        driver_ranking: 'Revisando conductores',
        fleet_overview: 'Revisando la flota',
        find_assets: 'Buscando la unidad',
        rank_assets: 'Comparando unidades',
        search_events: 'Buscando eventos',
    };

    return `${labels[toolName] ?? 'Consultando datos'}…`;
}
