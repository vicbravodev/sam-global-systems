import { X } from 'lucide-react';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { EXECUTION_SEGMENTS } from './copy';
import { ExecutionsList } from './executions-list';
import type {
    AutomationSummary,
    ExecutionFilters,
    ExecutionRow,
    ExecutionStatusFilter,
    Option,
} from './types';

export interface ExecutionsPanelProps {
    executions: ExecutionRow[] | undefined;
    summary: AutomationSummary;
    execFilters: ExecutionFilters;
    applyExecFilters: (next: ExecutionFilters) => void;
    /** Name of the workflow the list is narrowed to, if any. */
    filteredWorkflowName: string | null;
    actionTypes: Option[];
    canManage: boolean;
}

/** "Ejecuciones" tab: result filter, workflow chip and the last 30 days. */
export function ExecutionsPanel({
    executions,
    summary,
    execFilters,
    applyExecFilters,
    filteredWorkflowName,
    actionTypes,
    canManage,
}: ExecutionsPanelProps) {
    return (
        <>
            <div className="flex shrink-0 flex-wrap items-center gap-2 border-b border-border px-5 py-2">
                <SegmentedFilter
                    aria-label="Filtrar ejecuciones por resultado"
                    allLabel="Todas"
                    allCount={summary.executions.total}
                    value={execFilters.status}
                    onChange={(value) =>
                        applyExecFilters({
                            ...execFilters,
                            status: value as ExecutionStatusFilter | null,
                        })
                    }
                    options={EXECUTION_SEGMENTS.map((segment) => ({
                        ...segment,
                        count: summary.executions[segment.value],
                    }))}
                />
                {filteredWorkflowName && (
                    <button
                        type="button"
                        onClick={() =>
                            applyExecFilters({
                                ...execFilters,
                                workflow: null,
                            })
                        }
                        className="flex items-center gap-1 rounded-full border border-primary/40 bg-primary/10 px-2.5 py-1 text-2xs font-medium text-primary"
                        aria-label={`Quitar filtro: ${filteredWorkflowName}`}
                    >
                        {filteredWorkflowName}
                        <X size={12} />
                    </button>
                )}
                <span className="ml-auto text-2xs text-fg-3">
                    Últimos 30 días
                </span>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                <ExecutionsList
                    executions={executions ?? []}
                    actionTypes={actionTypes}
                    canManage={canManage}
                    filtered={
                        execFilters.status !== null ||
                        execFilters.workflow !== null
                    }
                />
                {(executions ?? []).length >= 100 && (
                    <p className="px-5 py-3 text-center text-2xs text-fg-3">
                        Se muestran las 100 más recientes. Usa los filtros para
                        encontrar otras.
                    </p>
                )}
            </div>
        </>
    );
}
