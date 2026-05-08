import type { IncidentDetail } from '@/types/sam';
import { DetailCenter } from './detail-center';
import { DetailHeader } from './detail-header';
import { DetailSide } from './detail-side';
import { DetailTimeline } from './detail-timeline';

interface IncidentDetailPanelProps {
    incident: IncidentDetail;
    onClose: () => void;
}

export function IncidentDetailPanel({
    incident,
    onClose,
}: IncidentDetailPanelProps) {
    return (
        <div className="flex min-w-0 flex-col overflow-hidden border-l border-border bg-background">
            <DetailHeader incident={incident} onClose={onClose} />

            <div className="grid min-h-0 flex-1 [grid-template-columns:minmax(220px,1fr)_minmax(0,1.5fr)_minmax(260px,1fr)] overflow-x-hidden overflow-y-auto max-[1280px]:[grid-template-columns:1fr]">
                {/* Col 1: Timeline */}
                <div className="overflow-y-auto border-r border-border p-4">
                    <DetailTimeline incident={incident} />
                </div>

                {/* Col 2: Center */}
                <div className="overflow-y-auto border-r border-border p-4">
                    <DetailCenter incident={incident} />
                </div>

                {/* Col 3: Side */}
                <div className="overflow-y-auto p-4">
                    <DetailSide incident={incident} />
                </div>
            </div>
        </div>
    );
}
