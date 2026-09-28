import type { PropsWithChildren } from 'react';
import { SettingsShell } from '@/components/sam/settings/settings-shell';

/**
 * Layout de Ajustes personales (perfil, seguridad, apariencia, avisos,
 * equipos). Comparte el índice lateral con la configuración de la empresa;
 * cada página pinta su propio encabezado y cuerpo con `SettingsPage`.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    return <SettingsShell>{children}</SettingsShell>;
}
