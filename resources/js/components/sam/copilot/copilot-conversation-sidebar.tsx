import { Link } from '@inertiajs/react';
import {
    BarChart3,
    MessageSquarePlus,
    Pin,
    PinOff,
    Trash2,
} from 'lucide-react';
import { timeAgo } from '@/components/sam/copilot/copilot-format';
import { Meter } from '@/components/sam/meter';
import { cn } from '@/lib/utils';
import copilotRoutes from '@/routes/copilot';
import type { CopilotConversation, CopilotQuota } from '@/types/copilot';

export interface CopilotConversationSidebarProps {
    conversations: CopilotConversation[];
    activeConversationId: number | null;
    quota: CopilotQuota;
    canViewUsage: boolean;
    teamSlug: string;
    onNew: () => void;
    onLoad: (conversation: CopilotConversation) => void;
    onTogglePin: (conversation: CopilotConversation) => void;
    onRemove: (conversation: CopilotConversation) => void;
}

export function CopilotConversationSidebar({
    conversations,
    activeConversationId,
    quota,
    canViewUsage,
    teamSlug,
    onNew,
    onLoad,
    onTogglePin,
    onRemove,
}: CopilotConversationSidebarProps) {
    return (
        <aside className="hidden min-h-0 flex-col border-r border-border bg-surface-1 md:flex">
            <div className="p-3">
                <button
                    type="button"
                    onClick={onNew}
                    className="flex w-full cursor-pointer items-center gap-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-sm font-medium text-fg-1 hover:bg-surface-3"
                >
                    <MessageSquarePlus className="size-4 text-ai-accent" />
                    <span className="flex-1 text-left">Nueva conversación</span>
                </button>
            </div>
            <div className="sam-caps px-4 pt-1 pb-1.5">Conversaciones</div>
            <nav className="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-2 pb-2">
                {conversations.length === 0 && (
                    <p className="px-2 py-3 text-xs text-fg-3">
                        Aquí aparecerán tus conversaciones. Son privadas: solo
                        tú las ves.
                    </p>
                )}
                {conversations.map((conversation) => (
                    <ConversationRow
                        key={conversation.id}
                        conversation={conversation}
                        active={conversation.id === activeConversationId}
                        onLoad={onLoad}
                        onTogglePin={onTogglePin}
                        onRemove={onRemove}
                    />
                ))}
            </nav>
            <QuotaMeter quota={quota} />
            {canViewUsage && (
                <Link
                    href={copilotRoutes.usage(teamSlug)}
                    className="mx-3 mb-3 flex items-center gap-2 rounded-md border border-border px-3 py-2 text-xs text-fg-2 hover:bg-surface-2 hover:text-fg-1"
                >
                    <BarChart3 className="size-3.5" /> Uso y consumo
                </Link>
            )}
        </aside>
    );
}

function ConversationRow({
    conversation,
    active,
    onLoad,
    onTogglePin,
    onRemove,
}: {
    conversation: CopilotConversation;
    active: boolean;
    onLoad: (conversation: CopilotConversation) => void;
    onTogglePin: (conversation: CopilotConversation) => void;
    onRemove: (conversation: CopilotConversation) => void;
}) {
    return (
        <div
            className={cn(
                'group relative rounded-md',
                active ? 'bg-primary/15' : 'hover:bg-surface-2',
            )}
        >
            <button
                type="button"
                onClick={() => onLoad(conversation)}
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
                    aria-label={conversation.isPinned ? 'Desfijar' : 'Fijar'}
                    onClick={() => onTogglePin(conversation)}
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
                    onClick={() => onRemove(conversation)}
                    className="grid size-6 cursor-pointer place-items-center rounded-sm text-fg-3 hover:bg-severity-critical/15 hover:text-severity-critical"
                >
                    <Trash2 className="size-3" />
                </button>
            </div>
        </div>
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
            <Meter
                value={percent}
                label="Consultas del mes usadas"
                className="mt-1.5 h-1"
                toneClassName={
                    percent >= 100
                        ? 'bg-severity-critical'
                        : percent >= 80
                          ? 'bg-severity-high'
                          : 'bg-ai-accent'
                }
            />
        </div>
    );
}
