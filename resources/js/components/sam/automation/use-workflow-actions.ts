import { useState } from 'react';
import { deleteJson, postJson, putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import automationRoutes from '@/routes/automation';
import type { WorkflowRow } from './types';

/** Switch on/off, run now and delete (with its confirmation) a workflow. */
export function useWorkflowActions(teamSlug: string | null) {
    const [deleting, setDeleting] = useState<WorkflowRow | null>(null);
    const [toggling, setToggling] = useState<number | null>(null);

    const toggle = async (workflow: WorkflowRow, next: boolean) => {
        if (teamSlug === null || toggling !== null) {
            return;
        }

        setToggling(workflow.id);
        // `status` y `is_active` van juntos: el motor sólo corre las que
        // tienen ambos en activo (un borrador encendido no hacía nada).
        await submit(
            putJson(
                automationRoutes.workflows.update.url([teamSlug, workflow.id]),
                {
                    is_active: next,
                    status: next ? 'active' : 'inactive',
                },
            ),
            next ? 'Automatización encendida.' : 'Automatización apagada.',
        );
        setToggling(null);
    };

    const runNow = (workflow: WorkflowRow) => {
        if (teamSlug === null) {
            return;
        }

        void submit(
            postJson(
                automationRoutes.workflows.trigger.url([teamSlug, workflow.id]),
                {
                    source_reference_id: `manual-${Date.now()}`,
                },
            ),
            'Automatización en marcha. Sigue su avance en Ejecuciones.',
        );
    };

    const remove = async (workflow: WorkflowRow) => {
        if (teamSlug === null) {
            return;
        }

        const result = await submit(
            deleteJson(
                automationRoutes.workflows.destroy.url([teamSlug, workflow.id]),
            ),
            'Automatización eliminada.',
        );

        if (result.ok) {
            setDeleting(null);
        }
    };

    return { toggling, toggle, runNow, deleting, setDeleting, remove };
}
