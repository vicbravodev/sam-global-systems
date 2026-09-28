import { Link, usePage } from '@inertiajs/react';
import { PhoneOff } from 'lucide-react';
import { edit as profileEdit } from '@/routes/profile';

/**
 * Canal de arranque (decisión 2026-09-28): mientras ningún admin/supervisor
 * del equipo tenga teléfono y correo verificados, SAM no puede avisar de una
 * emergencia ni encender la vigilancia. Sólo lo ve quien puede arreglarlo.
 */
export function TenantSetupBanner() {
    const { tenantSetup } = usePage().props;

    if (!tenantSetup || tenantSetup.ready) {
        return null;
    }

    const missing = [
        !tenantSetup.phone && 'un teléfono verificado',
        !tenantSetup.email && 'un correo verificado',
    ].filter(Boolean);

    return (
        <div
            role="alert"
            className="flex shrink-0 flex-col gap-2 border-b border-severity-high/40 bg-severity-high/10 px-4 py-2 text-sm text-fg-1 sm:flex-row sm:items-center sm:justify-between"
        >
            <span className="flex items-center gap-2">
                <PhoneOff size={16} className="shrink-0 text-severity-high" />
                <span>
                    SAM no tiene a quién avisar de una emergencia: falta{' '}
                    {missing.join(' y ')} de un administrador.
                </span>
            </span>
            <Link
                href={profileEdit()}
                className="shrink-0 rounded bg-fg-1/10 px-2.5 py-1 text-xs font-medium hover:bg-fg-1/20"
            >
                Verificar ahora
            </Link>
        </div>
    );
}
