import { usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { IncidentDetail } from '@/types/sam';
import { useIncidentActions } from './incident-actions-context';
import type { CommentVisibilityUi } from './incident-actions-context';
import { UserAvatar } from './user-avatar';

// ---- VisibilityChip ----

function VisibilityChip({ v }: { v: 'internal' | 'tenant' | 'audit' }) {
    const map = {
        internal: {
            label: 'Interno',
            cls: 'bg-surface-3 text-fg-3 border-border',
        },
        tenant: {
            label: 'Tenant',
            cls: 'bg-primary/10 text-primary border-primary/30',
        },
        audit: {
            label: 'Auditoría',
            cls: 'bg-severity-high/10 text-severity-high border-severity-high/30',
        },
    };
    const { label, cls } = map[v];

    return (
        <span
            className={cn(
                'inline-flex rounded-sm border px-1.5 py-0.5 text-3xs font-semibold tracking-caps uppercase',
                cls,
            )}
        >
            {label}
        </span>
    );
}

// ---- CommentComposer ----

function CommentComposer() {
    const page = usePage();
    const getInitials = useInitials();
    const { addComment, pending } = useIncidentActions();
    const [comment, setComment] = useState('');
    const [visibility, setVisibility] =
        useState<CommentVisibilityUi>('internal');
    const busy = pending === 'comment';

    const currentUserName =
        (page.props.auth?.user?.name as string | undefined) ?? null;
    const myInitials = currentUserName ? getInitials(currentUserName) : '··';

    const submit = async () => {
        if (comment.trim() === '') {
            return;
        }

        const ok = await addComment(comment.trim(), visibility);

        if (ok) {
            setComment('');
        }
    };

    return (
        <div className="mt-2.5 flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-1 p-2">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <UserAvatar initials={myInitials} size={24} />
                <input
                    type="text"
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            void submit();
                        }
                    }}
                    placeholder="Escribe un comentario…"
                    className="min-w-0 flex-1 border-none bg-transparent text-sm text-fg-1 outline-none placeholder:text-fg-3"
                />
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <Select
                    value={visibility}
                    onValueChange={(value) =>
                        setVisibility(value as CommentVisibilityUi)
                    }
                >
                    <SelectTrigger className="h-9">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="internal">Interno</SelectItem>
                        <SelectItem value="tenant">Tenant</SelectItem>
                        <SelectItem value="audit">Auditoría</SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    size="sm"
                    variant="default"
                    onClick={() => void submit()}
                    disabled={busy || comment.trim() === ''}
                >
                    {busy ? (
                        <Loader2 size={12} className="animate-spin" />
                    ) : null}
                    Comentar
                </Button>
            </div>
        </div>
    );
}

// ---- CommentsSection ----

export function CommentsSection({ incident }: { incident: IncidentDetail }) {
    return (
        <section>
            <h3 className="mb-3 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                Comentarios
                {incident.comments.length > 0 && (
                    <span className="ml-1.5 font-mono text-fg-3 normal-case">
                        {incident.comments.length}
                    </span>
                )}
            </h3>

            {incident.comments.length > 0 && (
                <div className="mb-4 flex flex-col gap-3">
                    {incident.comments.map((c, idx) => (
                        <div key={idx} className="flex items-start gap-2.5">
                            <UserAvatar initials={c.authorInitials} size={24} />
                            <div className="min-w-0 flex-1">
                                <div className="mb-1 flex flex-wrap items-center gap-2">
                                    <span className="text-xs font-semibold text-fg-1">
                                        {c.authorName}
                                    </span>
                                    <VisibilityChip v={c.visibility} />
                                    <span className="text-2xs text-fg-3">
                                        {c.relativeTime}
                                    </span>
                                </div>
                                <p className="text-sm leading-normal text-fg-1">
                                    {c.body}
                                </p>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <CommentComposer />
        </section>
    );
}
