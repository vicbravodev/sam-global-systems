import { createInertiaApp } from '@inertiajs/react';
import '@/bootstrap';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AdminLayout from '@/layouts/admin-layout';
import AuthLayout from '@/layouts/auth-layout';
import OpsLayout from '@/layouts/ops-layout';
import SettingsLayout from '@/layouts/settings/layout';
import TenantSettingsLayout from '@/layouts/settings/tenant-settings-layout';

const appName = import.meta.env.VITE_APP_NAME || 'SAM';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name.startsWith('errors/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            // Team-scoped settings (roles, tenant config) viven en el shell
            // ops con la sub-nav unificada de Ajustes (C2), a ancho completo.
            // Debe coincidir antes de `settings/`.
            case name.startsWith('settings/roles'):
            case name.startsWith('settings/tenant-config'):
                return [OpsLayout, TenantSettingsLayout];
            // Settings de usuario y equipos viven en el shell ops con la
            // sub-nav unificada de Ajustes (C2: un solo menú con sub-secciones).
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [OpsLayout, SettingsLayout];
            case name.startsWith('admin/'):
                return AdminLayout;
            case name === 'dashboard':
            case name.startsWith('assets/'):
            case name.startsWith('drivers/'):
            case name.startsWith('incidents/'):
            case name.startsWith('integrations/'):
            case name.startsWith('notifications/'):
            case name.startsWith('rules/'):
            case name.startsWith('automation/'):
            case name.startsWith('analytics/'):
            case name.startsWith('audit/'):
            case name.startsWith('billing/'):
            case name.startsWith('events/'):
                return OpsLayout;
            default:
                return OpsLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
