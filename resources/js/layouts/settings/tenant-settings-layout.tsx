import type { PropsWithChildren } from 'react';
import { SettingsShell } from '@/components/sam/settings/settings-shell';

/**
 * Layout de la configuración de la empresa (Emergencias, IA, Avisos…,
 * Equipo y roles). Mismo marco que los ajustes personales: el índice lateral
 * lista las secciones y cada página ocupa el resto del ancho.
 */
export default function TenantSettingsLayout({ children }: PropsWithChildren) {
    return <SettingsShell>{children}</SettingsShell>;
}
