import { usePage } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useMemo,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { postJson, readErrorMessage } from '@/lib/sam-fetch';
import type {
    IncidentDetail,
    InboxMember,
    ReclassifyOptions,
} from '@/types/sam';

export type CommentVisibilityUi = 'internal' | 'tenant' | 'audit';

const VISIBILITY_API: Record<CommentVisibilityUi, string> = {
    internal: 'internal',
    tenant: 'tenant_visible',
    audit: 'audit_only',
};

export interface ResolvePayload {
    resolutionCode: string;
    summary: string;
    rootCause?: string;
    correctiveAction?: string;
    preventiveAction?: string;
}

export interface IncidentActionsValue {
    incident: IncidentDetail;
    members: InboxMember[];
    reclassifyOptions: ReclassifyOptions;
    currentUserId: number | null;
    /** Key of the action currently in flight, or null when idle. */
    pending: string | null;
    assignTo: (userId: number) => Promise<boolean>;
    assignToMe: () => Promise<boolean>;
    addComment: (
        body: string,
        visibility: CommentVisibilityUi,
    ) => Promise<boolean>;
    resolve: (payload: ResolvePayload) => Promise<boolean>;
    closeIncident: (summary?: string) => Promise<boolean>;
    reopen: () => Promise<boolean>;
    reclassify: (typeId: number, priorityId: number | null) => Promise<boolean>;
    escalate: (reason?: string) => Promise<boolean>;
    discard: (summary?: string) => Promise<boolean>;
    confirmAi: () => Promise<boolean>;
    feedbackAi: (reason: string) => Promise<boolean>;
}

const IncidentActionsContext = createContext<IncidentActionsValue | null>(null);

interface PageProps {
    currentTeam?: { slug?: string | null } | null;
    auth?: { user?: { id?: number | null } | null } | null;
    members?: InboxMember[];
    reclassifyOptions?: ReclassifyOptions;
}

interface ProviderProps {
    incident: IncidentDetail;
    /** Called after every successful mutation to refresh inbox + detail. */
    onMutated: () => void;
    children: ReactNode;
}

export function IncidentActionsProvider({
    incident,
    onMutated,
    children,
}: ProviderProps) {
    const page = usePage();
    const props = page.props as unknown as PageProps;

    const teamSlug = props.currentTeam?.slug ?? null;
    const currentUserId = props.auth?.user?.id ?? null;
    const members = useMemo(() => props.members ?? [], [props.members]);
    const reclassifyOptions = useMemo(
        () => props.reclassifyOptions ?? { types: [], priorities: [] },
        [props.reclassifyOptions],
    );

    const [pending, setPending] = useState<string | null>(null);

    const base = teamSlug
        ? `/${teamSlug}/incidents/${incident.incidentId}`
        : null;

    const run = useCallback(
        async (
            key: string,
            url: string | null,
            body: Record<string, unknown>,
            successMessage: string,
        ): Promise<boolean> => {
            if (url === null) {
                toast.error('No hay equipo activo.');

                return false;
            }

            setPending(key);

            try {
                const response = await postJson(url, body);

                if (response.ok) {
                    toast.success(successMessage);
                    onMutated();

                    return true;
                }

                if (response.status === 403) {
                    toast.error('No tienes permisos para esta acción.');
                } else {
                    const message = await readErrorMessage(response);
                    toast.error(message ?? 'No se pudo completar la acción.');
                }

                return false;
            } catch {
                toast.error('Error de red. Vuelve a intentarlo.');

                return false;
            } finally {
                setPending(null);
            }
        },
        [onMutated],
    );

    const value = useMemo<IncidentActionsValue>(() => {
        const assignTo = (userId: number) =>
            run(
                'assign',
                base ? `${base}/assign` : null,
                { assigned_to_type: 'user', assigned_to_id: userId },
                'Incidente asignado.',
            );

        return {
            incident,
            members,
            reclassifyOptions,
            currentUserId,
            pending,
            assignTo,
            assignToMe: () => {
                if (currentUserId === null) {
                    toast.error('No se pudo identificar tu usuario.');

                    return Promise.resolve(false);
                }

                return assignTo(currentUserId);
            },
            addComment: (body, visibility) =>
                run(
                    'comment',
                    base ? `${base}/comments` : null,
                    { comment: body, visibility: VISIBILITY_API[visibility] },
                    'Comentario agregado.',
                ),
            resolve: (payload) =>
                run(
                    'resolve',
                    base ? `${base}/resolve` : null,
                    {
                        resolution_code: payload.resolutionCode,
                        summary: payload.summary,
                        root_cause: payload.rootCause ?? null,
                        corrective_action: payload.correctiveAction ?? null,
                        preventive_action: payload.preventiveAction ?? null,
                    },
                    'Incidente resuelto.',
                ),
            closeIncident: (summary) =>
                run(
                    'close',
                    base ? `${base}/close` : null,
                    summary ? { summary } : {},
                    'Incidente cerrado.',
                ),
            reopen: () =>
                run(
                    'reopen',
                    base ? `${base}/reopen` : null,
                    {},
                    'Incidente reabierto.',
                ),
            reclassify: (typeId, priorityId) =>
                run(
                    'reclassify',
                    base ? `${base}/reclassify` : null,
                    {
                        incident_type_id: typeId,
                        incident_priority_id: priorityId,
                    },
                    'Incidente reclasificado.',
                ),
            escalate: (reason) =>
                run(
                    'escalate',
                    base ? `${base}/escalate` : null,
                    reason ? { reason } : {},
                    'Incidente escalado.',
                ),
            discard: (summary) =>
                run(
                    'discard',
                    base ? `${base}/resolve` : null,
                    {
                        resolution_code: 'false_positive',
                        summary: summary ?? 'Descartado por el operador.',
                    },
                    'Incidente descartado.',
                ),
            confirmAi: () =>
                run(
                    'confirm-ai',
                    base ? `${base}/comments` : null,
                    {
                        comment: 'Evaluación de IA confirmada por el operador.',
                        visibility: 'audit_only',
                    },
                    'Evaluación IA confirmada.',
                ),
            feedbackAi: (reason) => {
                if (incident.aiEvaluationId === null) {
                    toast.error('No hay evaluación de IA para reevaluar.');

                    return Promise.resolve(false);
                }

                return run(
                    'feedback-ai',
                    teamSlug
                        ? `/${teamSlug}/ai/evaluations/${incident.aiEvaluationId}/reevaluate`
                        : null,
                    { reason },
                    'Reevaluación solicitada.',
                );
            },
        };
    }, [
        base,
        currentUserId,
        incident,
        members,
        pending,
        reclassifyOptions,
        run,
        teamSlug,
    ]);

    return (
        <IncidentActionsContext.Provider value={value}>
            {children}
        </IncidentActionsContext.Provider>
    );
}

export function useIncidentActions(): IncidentActionsValue {
    const ctx = useContext(IncidentActionsContext);

    if (ctx === null) {
        throw new Error(
            'useIncidentActions must be used within an IncidentActionsProvider',
        );
    }

    return ctx;
}
