import { usePage } from '@inertiajs/react';
import { Maximize2, MessageSquarePlus, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getJson } from '@/lib/sam-fetch';
import type { CopilotCatalog, CopilotQuota } from '@/types/copilot';
import {
    CopilotBubbleConnecting,
    CopilotBubbleFrame,
} from './copilot-bubble-frame';
import { CopilotChatPanel } from './copilot-chat-panel';
import { useCopilotChat } from './use-copilot-chat';

type CatalogState =
    | { status: 'idle' | 'loading' | 'failed' }
    | { status: 'ready'; data: CopilotCatalog };

export interface CopilotBubbleProps {
    teamSlug: string;
    open: boolean;
    onClose: () => void;
    /** Leaves the bubble for the full Copilot page, keeping the thread. */
    onOpenFull: (conversationId: number | null) => void;
}

/**
 * The open Copilot bubble: thread state, fleet catalog and chat panel.
 *
 * Loaded on demand by the launcher the first time the bubble opens (the chat
 * panel and its blocks are heavy), then kept mounted while closed so the
 * conversation and the catalog survive closing and reopening the bubble.
 */
export default function CopilotBubble({
    teamSlug,
    open,
    onClose,
    onOpenFull,
}: CopilotBubbleProps) {
    const page = usePage();
    const team = page.props.currentTeam;
    const user = page.props.auth?.user;

    const [catalog, setCatalog] = useState<CatalogState>({ status: 'idle' });
    const catalogInFlight = useRef(false);

    const chat = useCopilotChat({
        teamSlug,
        channel: 'bubble',
        onQuota: useCallback(
            (quota: CopilotQuota) =>
                setCatalog((current) =>
                    current.status === 'ready'
                        ? { ...current, data: { ...current.data, quota } }
                        : current,
                ),
            [],
        ),
    });

    const loadCatalog = useCallback(() => {
        if (catalogInFlight.current) {
            return;
        }

        catalogInFlight.current = true;
        setCatalog({ status: 'loading' });
        getJson(`/${teamSlug}/copilot/catalog`)
            .then((response) =>
                response.ok ? response.json() : Promise.reject(),
            )
            .then((data: CopilotCatalog) =>
                setCatalog({ status: 'ready', data }),
            )
            .catch(() => setCatalog({ status: 'failed' }))
            .finally(() => {
                catalogInFlight.current = false;
            });
    }, [teamSlug]);

    // Each opening retries a catalog that never loaded or failed.
    const needsCatalog =
        catalog.status === 'idle' || catalog.status === 'failed';

    useEffect(() => {
        if (open && needsCatalog) {
            loadCatalog();
        }
        // Only an opening triggers the load, not a failure while open (that
        // one offers "Reintentar").
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    if (!open) {
        return null;
    }

    return (
        <CopilotBubbleFrame onClose={onClose}>
            <div className="flex shrink-0 items-center gap-2 border-b border-border bg-surface-1 px-3 py-2.5">
                <div className="grid size-7 place-items-center rounded-md bg-ai-accent-bg text-ai-accent">
                    <Sparkles className="size-3.5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-fg-1">
                        SAM Copilot
                    </div>
                    <div className="truncate font-mono text-3xs text-fg-3">
                        {team?.name}
                    </div>
                </div>
                <HeadButton label="Nueva conversación" onClick={chat.reset}>
                    <MessageSquarePlus className="size-3.5" />
                </HeadButton>
                <HeadButton
                    label="Abrir vista completa"
                    onClick={() => onOpenFull(chat.conversationId)}
                >
                    <Maximize2 className="size-3.5" />
                </HeadButton>
                <HeadButton label="Cerrar (Esc)" onClick={onClose}>
                    <X className="size-3.5" />
                </HeadButton>
            </div>
            {catalog.status === 'ready' ? (
                <CopilotChatPanel
                    chat={chat}
                    catalog={catalog.data}
                    userName={user?.name ?? ''}
                    teamName={team?.name ?? ''}
                    compact
                />
            ) : catalog.status === 'failed' ? (
                <CopilotBubbleConnecting>
                    No pude conectar con SAM Copilot.
                    <button
                        type="button"
                        onClick={loadCatalog}
                        className="cursor-pointer rounded-md border border-border bg-surface-2 px-3 py-1.5 text-xs font-medium text-fg-1 transition-transform duration-(--motion-fast) ease-(--ease-out) hover:bg-surface-3 active:scale-97"
                    >
                        Reintentar
                    </button>
                </CopilotBubbleConnecting>
            ) : (
                <CopilotBubbleConnecting />
            )}
        </CopilotBubbleFrame>
    );
}

function HeadButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    onClick={onClick}
                    className="grid size-7 cursor-pointer place-items-center rounded-md text-fg-3 hover:bg-surface-2 hover:text-fg-1"
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
