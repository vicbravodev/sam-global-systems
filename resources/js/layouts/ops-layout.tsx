import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { RealtimeBootstrap } from '@/components/realtime-bootstrap';
import { CommandPalette } from '@/components/sam/command-palette';
import { OpsSidebar } from '@/components/sam/ops-sidebar';
import { OpsTopbar } from '@/components/sam/ops-topbar';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { BreadcrumbItem } from '@/types';

interface OpsLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default function OpsLayout({
    children,
    breadcrumbs = [],
}: OpsLayoutProps) {
    const [commandOpen, setCommandOpen] = useState(false);
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
                <ImpersonationBanner />
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
                    <CommandPalette
                        open={commandOpen}
                        onClose={() => setCommandOpen(false)}
                    />
                </div>
            </div>
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
