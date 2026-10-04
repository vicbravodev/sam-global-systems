import { router, usePage } from '@inertiajs/react';
import { lazy, Suspense, useEffect, useState } from 'react';
import { CriticalIncidentAlert } from '@/components/critical-incident-alert';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { PushOptInBanner } from '@/components/push-opt-in-banner';
import { RealtimeBootstrap } from '@/components/realtime-bootstrap';
import { CopilotLauncher } from '@/components/sam/copilot/copilot-launcher';
import { OpsSidebar } from '@/components/sam/ops-sidebar';
import { OpsTopbar } from '@/components/sam/ops-topbar';
import { TenantSetupBanner } from '@/components/tenant-setup-banner';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { BreadcrumbItem } from '@/types';

// The palette UI downloads on its first ⌘K; the shortcut listener below stays
// in the layout.
const CommandPalette = lazy(() =>
    import('@/components/sam/command-palette').then((module) => ({
        default: module.CommandPalette,
    })),
);

interface OpsLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default function OpsLayout({
    children,
    breadcrumbs = [],
}: OpsLayoutProps) {
    const [commandOpen, setCommandOpen] = useState(false);
    // Mounted on first open and kept mounted, as before (query survives).
    const [commandMounted, setCommandMounted] = useState(false);

    if (commandOpen && !commandMounted) {
        setCommandMounted(true);
    }

    const [mobileNavOpen, setMobileNavOpen] = useState(false);
    const navBadges = usePage().props.navBadges ?? { inbox: 0 };

    useEffect(() => {
        const handleKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setCommandOpen((prev) => !prev);
            }
        };

        window.addEventListener('keydown', handleKey);

        return () => window.removeEventListener('keydown', handleKey);
    }, []);

    // El layout persiste entre visitas Inertia: cerrar el drawer al navegar.
    useEffect(() => {
        return router.on('navigate', () => setMobileNavOpen(false));
    }, []);

    return (
        <>
            <RealtimeBootstrap />
            <div className="flex h-dvh flex-col overflow-hidden">
                <CriticalIncidentAlert />
                <ImpersonationBanner />
                <TenantSetupBanner />
                <PushOptInBanner />
                <div className="grid min-h-0 flex-1 grid-cols-[auto_1fr] overflow-hidden">
                    <OpsSidebar navBadges={navBadges} />
                    <div className="flex min-w-0 flex-col overflow-hidden">
                        <OpsTopbar
                            breadcrumbs={breadcrumbs}
                            onOpenCommandPalette={() => setCommandOpen(true)}
                            onOpenMobileNav={() => setMobileNavOpen(true)}
                        />
                        {children}
                    </div>
                    {commandMounted && (
                        <Suspense fallback={null}>
                            <CommandPalette
                                open={commandOpen}
                                onClose={() => setCommandOpen(false)}
                            />
                        </Suspense>
                    )}
                </div>
            </div>
            <CopilotLauncher />
            <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
                <SheetContent
                    side="left"
                    className="w-[260px] gap-0 p-0 lg:hidden"
                >
                    <SheetHeader className="sr-only">
                        <SheetTitle>Navegación</SheetTitle>
                    </SheetHeader>
                    <OpsSidebar mobile navBadges={navBadges} />
                </SheetContent>
            </Sheet>
        </>
    );
}
