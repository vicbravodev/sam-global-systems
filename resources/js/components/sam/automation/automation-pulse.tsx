import {
    Activity,
    AlertTriangle,
    CirclePause,
    CirclePlay,
    Hourglass,
} from 'lucide-react';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { formatNumber } from '@/lib/format';
import type {
    AutomationSummary,
    AutomationTab,
    ExecutionFilters,
    ExecutionStatusFilter,
    WorkflowFilter,
} from './types';

export interface AutomationPulseProps {
    summary: AutomationSummary;
    tab: AutomationTab;
    workflowFilter: WorkflowFilter | null;
    execFilters: ExecutionFilters;
    onShowWorkflows: (filter: WorkflowFilter | null) => void;
    onShowExecutions: (status: ExecutionStatusFilter | null) => void;
}

/** Workflow and execution figures; each tile jumps to its filtered tab. */
export function AutomationPulse({
    summary,
    tab,
    workflowFilter,
    execFilters,
    onShowWorkflows,
    onShowExecutions,
}: AutomationPulseProps) {
    return (
        <PulseStrip>
            <PulseStat
                label="Encendidas"
                value={summary.workflows.active}
                icon={CirclePlay}
                tone={summary.workflows.active > 0 ? 'ok' : 'neutral'}
                hint="se activan solas"
                onClick={() =>
                    onShowWorkflows(
                        workflowFilter === 'active' && tab === 'workflows'
                            ? null
                            : 'active',
                    )
                }
                active={tab === 'workflows' && workflowFilter === 'active'}
            />
            <PulseStat
                label="Apagadas"
                value={summary.workflows.inactive}
                icon={CirclePause}
                hint="no hacen nada"
                onClick={() =>
                    onShowWorkflows(
                        workflowFilter === 'inactive' && tab === 'workflows'
                            ? null
                            : 'inactive',
                    )
                }
                active={tab === 'workflows' && workflowFilter === 'inactive'}
            />
            <PulseStat
                label="Acciones 30 d"
                value={formatNumber(summary.executions.total)}
                icon={Activity}
                tone="info"
                hint="hechas por automatizaciones"
                onClick={() => onShowExecutions(null)}
                active={
                    tab === 'executions' &&
                    execFilters.status === null &&
                    execFilters.workflow === null
                }
            />
            <PulseStat
                label="Fallidas"
                value={summary.executions.failed}
                icon={AlertTriangle}
                tone={summary.executions.failed > 0 ? 'critical' : 'neutral'}
                hint={
                    summary.executions.failed > 0
                        ? 'revisa y reintenta'
                        : 'sin fallos en 30 días'
                }
                onClick={() => onShowExecutions('failed')}
                active={tab === 'executions' && execFilters.status === 'failed'}
            />
            <PulseStat
                label="Esperan confirmación"
                value={summary.executions.pending}
                icon={Hourglass}
                tone={summary.executions.pending > 0 ? 'warn' : 'neutral'}
                hint={
                    summary.executions.pending > 0
                        ? 'necesitan tu visto bueno'
                        : 'nada pendiente'
                }
                live={summary.executions.pending > 0}
                onClick={() => onShowExecutions('pending')}
                active={
                    tab === 'executions' && execFilters.status === 'pending'
                }
            />
        </PulseStrip>
    );
}
