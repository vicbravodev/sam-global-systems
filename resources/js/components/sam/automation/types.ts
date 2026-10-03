import type { ConditionFieldDef } from '@/components/sam/condition-builder';

/** Paso tal como vive en `steps_json`; conserva claves que la UI no edita. */
export interface WorkflowStep {
    order?: number;
    action_type?: string;
    execution_mode?: string;
    target_type?: string;
    target_reference?: string | null;
    delay_seconds?: number;
    [key: string]: unknown;
}

export interface WorkflowRow {
    id: number;
    code: string;
    name: string;
    description: string | null;
    triggerType: string | null;
    triggerConditions: Record<string, unknown> | null;
    status: string | null;
    steps: WorkflowStep[];
    /** Destino legible de cada paso ("Rol: Monitorista"). */
    stepTargets: string[];
    /** Sólo el nombre del destino ("Monitorista"), null si no tiene. */
    stepRecipients?: (string | null)[];
    isActive: boolean;
}

export interface WorkflowRunStat {
    lastRunAt: string | null;
    lastStatus: string | null;
    runs30d: number;
    failed30d: number;
}

export interface ExecutionRow {
    id: number;
    actionType: string | null;
    status: string | null;
    executionMode: string | null;
    targetType: string | null;
    targetReference: string | null;
    targetLabel: string;
    targetName?: string | null;
    statusLabel: string | null;
    sourceType?: string | null;
    workflowId?: number | null;
    workflowName?: string | null;
    incidentId: number | null;
    incidentReference?: string | null;
    incidentTitle?: string | null;
    attempts: number;
    errorMessage: string | null;
    isStub: boolean;
    executedAt: string | null;
    createdAt: string | null;
}

export type ExecutionStatusFilter =
    | 'failed'
    | 'pending'
    | 'in_progress'
    | 'completed'
    | 'cancelled';

export interface AutomationSummary {
    workflows: { total: number; active: number; inactive: number };
    executions: Record<ExecutionStatusFilter | 'total', number>;
}

export interface Option {
    value: string;
    label: string;
    description?: string;
}

export interface AutomationOptions {
    actionTypes: Option[];
    triggerTypes: Option[];
    statuses: string[];
}

export interface TeamTargets {
    users: Option[];
    roles: Option[];
}

export interface AutomationPageProps {
    workflows: WorkflowRow[];
    runStats: Record<number, WorkflowRunStat>;
    executions: ExecutionRow[];
    executionFilters?: {
        status: ExecutionStatusFilter | null;
        workflow: number | null;
    };
    summary?: AutomationSummary;
    options: AutomationOptions;
    triggerConditionFields: Record<string, ConditionFieldDef[]>;
    teamTargets: TeamTargets;
    canManage: boolean;
}

export type AutomationTab = 'workflows' | 'executions';

export type WorkflowFilter = 'active' | 'inactive';

export interface ExecutionFilters {
    status: ExecutionStatusFilter | null;
    workflow: number | null;
}
