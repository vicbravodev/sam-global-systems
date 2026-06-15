import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { SettingsNav } from '@/components/sam/settings-nav';
import { Separator } from '@/components/ui/separator';

/**
 * C2: layout de las secciones de Ajustes que son del tenant (Configuración,
 * Equipo y roles). Comparte la sub-nav unificada con la cuenta, pero deja el
 * contenido a ancho completo (las tablas/tabs del tenant no caben en la
 * columna estrecha de la cuenta).
 */
export default function TenantSettingsLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-6">
            <Heading
                title="Ajustes"
                description="Tu cuenta y la configuración del tenant en un solo lugar"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full lg:w-56 lg:shrink-0">
                    <SettingsNav />
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="min-w-0 flex-1">{children}</div>
            </div>
        </div>
    );
}
