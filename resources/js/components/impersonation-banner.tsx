import { router, usePage } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { destroy as impersonateDestroy } from '@/routes/admin/impersonate';

/**
 * Sticky warning bar shown while a super-admin is impersonating a tenant. The
 * `impersonation` prop is shared from HandleInertiaRequests and is only truthy
 * when the operator's current team is one they do not belong to.
 */
export function ImpersonationBanner() {
    const { impersonation } = usePage().props;

    if (!impersonation?.active) {
        return null;
    }

    return (
        <div className="flex shrink-0 items-center justify-between gap-3 bg-accent px-4 py-2 text-sm text-accent-foreground">
            <span className="flex items-center gap-2">
                <ShieldAlert size={16} className="shrink-0" />
                Estás viendo como <strong>{impersonation.team.name}</strong> —
                impersonación de super-admin.
            </span>
            <button
                type="button"
                onClick={() => router.delete(impersonateDestroy().url)}
                className="rounded bg-accent-foreground/10 px-2.5 py-1 text-xs font-medium hover:bg-accent-foreground/20"
            >
                Salir de impersonación
            </button>
        </div>
    );
}
