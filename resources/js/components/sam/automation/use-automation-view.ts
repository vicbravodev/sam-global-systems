import { router } from '@inertiajs/react';
import { useState } from 'react';
import type {
    AutomationTab,
    ExecutionFilters,
    ExecutionStatusFilter,
    WorkflowFilter,
} from './types';

/**
 * Which tab the automation page shows and its filters: the workflow on/off
 * filter is local, the execution filters reload `executions` from the server.
 * Opens on "Ejecuciones" when the URL already carries execution filters.
 */
export function useAutomationView(serverFilters: ExecutionFilters) {
    const [tab, setTab] = useState<AutomationTab>(
        serverFilters.status !== null || serverFilters.workflow !== null
            ? 'executions'
            : 'workflows',
    );
    const [workflowFilter, setWorkflowFilter] = useState<WorkflowFilter | null>(
        null,
    );
    const [execFilters, setExecFilters] =
        useState<ExecutionFilters>(serverFilters);

    const applyExecFilters = (next: ExecutionFilters) => {
        setExecFilters(next);
        router.reload({
            only: ['executions', 'executionFilters'],
            data: {
                execution_status: next.status ?? undefined,
                execution_workflow: next.workflow ?? undefined,
            },
        });
    };

    const showWorkflows = (filter: WorkflowFilter | null) => {
        setTab('workflows');
        setWorkflowFilter(filter);
    };

    const showExecutions = (status: ExecutionStatusFilter | null) => {
        setTab('executions');

        if (status !== execFilters.status || execFilters.workflow !== null) {
            applyExecFilters({ status, workflow: null });
        }
    };

    /** "Ejecuciones" narrowed to one workflow. */
    const showWorkflowExecutions = (workflowId: number) => {
        setTab('executions');
        applyExecFilters({ status: null, workflow: workflowId });
    };

    return {
        tab,
        setTab,
        workflowFilter,
        setWorkflowFilter,
        execFilters,
        applyExecFilters,
        showWorkflows,
        showExecutions,
        showWorkflowExecutions,
    };
}
