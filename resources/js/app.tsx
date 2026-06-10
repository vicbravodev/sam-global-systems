import { createInertiaApp } from '@inertiajs/react';
import '@/bootstrap';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AdminLayout from '@/layouts/admin-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import OpsLayout from '@/layouts/ops-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            // Team-scoped settings (roles, tenant config) live in the ops
            // shell, not in the user-level settings layout. Must match
            // before `settings/`.
            case name.startsWith('settings/roles'):
            case name.startsWith('settings/tenant-config'):
                return OpsLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [AppLayout, SettingsLayout];
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
            case name.startsWith('events/'):
                return OpsLayout;
            default:
                return AppLayout;
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
