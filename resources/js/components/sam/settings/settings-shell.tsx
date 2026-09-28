import type { PropsWithChildren } from 'react';
import {
    SettingsNav,
    SettingsNavSwitcher,
} from '@/components/sam/settings-nav';

/**
 * Marco común de Ajustes: índice lateral fijo en escritorio (con su propio
 * scroll) y un selector compacto encima del contenido en pantallas estrechas.
 * Cada página pinta su encabezado y su cuerpo scrolleable con `SettingsPage`.
 */
export function SettingsShell({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-0 flex-1 overflow-hidden">
            <aside className="hidden w-60 shrink-0 overflow-y-auto border-r border-border bg-surface-1 px-3 py-5 lg:block">
                <SettingsNav />
            </aside>

            <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                <div className="shrink-0 border-b border-border bg-surface-1 px-4 py-2.5 lg:hidden">
                    <SettingsNavSwitcher />
                </div>
                {children}
            </div>
        </div>
    );
}
