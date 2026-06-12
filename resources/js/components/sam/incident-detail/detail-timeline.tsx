import {
    AlertOctagon,
    ChevronRight,
    Info,
    Plug,
    Radar,
    RefreshCw,
    User,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { IncidentDetail, TimelineEntryType } from '@/types/sam';

const TYPE_ICON: Record<TimelineEntryType, LucideIcon> = {
    system: RefreshCw,
    webhook: Plug,
    ai: Radar,
    user: User,
    critical: AlertOctagon,
    assign: ChevronRight,
    comment: Info,
};

interface DetailTimelineProps {
    incident: IncidentDetail;
}

export function DetailTimeline({ incident }: DetailTimelineProps) {
    return (
        <div className="flex flex-col gap-4">
            {/* Timeline */}
            <section>
                <h3 className="mb-3 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                    Timeline
                </h3>
                <ol
                    className="relative m-0 list-none p-0 before:absolute before:top-2.5 before:bottom-2.5 before:left-[11px] before:w-px before:bg-border"
                    role="list"
                >
                    {incident.timeline.map((entry, idx) => {
                        const Icon = TYPE_ICON[entry.type];
                        const isAi = entry.type === 'ai';
                        const isCritical = entry.type === 'critical';

                        return (
                            <li
                                key={idx}
                                className="grid items-start gap-2 py-1.5 text-xs"
                                style={{
                                    gridTemplateColumns: '24px 1fr auto',
                                }}
                            >
                                <span
                                    className={cn(
                                        'z-[1] inline-grid place-items-center rounded-full border',
                                        isAi
                                            ? 'border-ai-accent/40 bg-ai-accent-bg text-ai-accent'
                                            : isCritical
                                              ? 'border-transparent bg-severity-critical text-white'
                                              : 'border-border bg-surface-2 text-fg-2',
                                    )}
                                    style={{ width: 22, height: 22 }}
                                >
                                    <Icon
                                        size={11}
                                        strokeWidth={isCritical ? 2 : 1.5}
                                    />
                                </span>

                                <div className="min-w-0">
                                    <div>
                                        <strong className="font-semibold text-fg-1">
                                            {entry.actor}
                                        </strong>{' '}
                                        <span className="text-fg-2">
                                            {entry.text}
                                        </span>
                                    </div>
                                    {entry.sub && (
                                        <div className="mt-0.5 text-2xs text-fg-3">
                                            {entry.sub}
                                        </div>
                                    )}
                                </div>

                                <span className="font-mono text-3xs whitespace-nowrap text-fg-3">
                                    {entry.ts}
                                </span>
                            </li>
                        );
                    })}
                </ol>
            </section>

            {/* Related links */}
            {incident.relatedLinks.length > 0 && (
                <section>
                    <h3 className="mb-2 text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                        Relacionados · {incident.relatedLinks.length}
                    </h3>
                    <ul className="m-0 list-none p-0 text-xs text-fg-2">
                        {incident.relatedLinks.map((link, idx) => {
                            const decisionColor =
                                link.decision === 'incident'
                                    ? 'text-severity-critical'
                                    : link.decision === 'escalate'
                                      ? 'text-severity-high'
                                      : link.decision === 'info'
                                        ? 'text-severity-info'
                                        : 'text-fg-3';

                            return (
                                <li
                                    key={idx}
                                    className="flex flex-wrap items-center gap-2 border-b border-border py-2 last:border-b-0"
                                >
                                    <span className="shrink-0 font-mono text-3xs text-fg-3">
                                        {link.ts}
                                    </span>
                                    <span className="shrink-0 font-mono text-2xs text-fg-2">
                                        {link.eventType}
                                    </span>
                                    <span className="shrink-0 font-mono text-2xs text-fg-1">
                                        {link.asset}
                                    </span>
                                    <span
                                        className={cn(
                                            'text-2xs font-semibold',
                                            decisionColor,
                                        )}
                                    >
                                        {link.decision}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            )}
        </div>
    );
}
