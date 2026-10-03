import { DetailResizer } from '@/components/sam/detail-resizer';
import { IncidentDetailPanel } from '@/components/sam/incident-detail';
import incidentRoutes from '@/routes/incidents';
import type { IncidentDetail } from '@/types/sam';
import { DetailPlaceholder } from './detail-placeholder';

export interface InboxDetailColumnProps {
    detail: IncidentDetail | null;
    loading: boolean;
    teamSlug: string | null;
    onClose: () => void;
    onMutated: () => void;
}

/**
 * DETAIL PANEL — side column on md+, full-screen overlay on mobile. `grid`
 * (not `flex`) so the single child stretches to fill both axes by default,
 * matching what the previous `md:contents` trick gave for free.
 * DetailResizer needs a real box (not `display: contents`) as its parent to
 * read a meaningful width, hence the change.
 */
export function InboxDetailColumn({
    detail,
    loading,
    teamSlug,
    onClose,
    onMutated,
}: InboxDetailColumnProps) {
    return (
        <div className="relative grid min-h-0 min-w-0 overflow-hidden max-md:fixed max-md:inset-0 max-md:z-40 max-md:bg-background">
            <DetailResizer
                min={420}
                defaultWidth={700}
                className="max-md:hidden"
            />
            {detail ? (
                <IncidentDetailPanel
                    incident={detail}
                    onClose={onClose}
                    onMutated={onMutated}
                    detailHref={
                        teamSlug
                            ? incidentRoutes.show.url([
                                  teamSlug,
                                  detail.incidentId,
                              ])
                            : undefined
                    }
                />
            ) : (
                <DetailPlaceholder loading={loading} onClose={onClose} />
            )}
        </div>
    );
}
