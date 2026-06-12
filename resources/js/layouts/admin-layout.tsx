import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { RealtimeBootstrap } from '@/components/realtime-bootstrap';
import { AdminSidebar } from '@/components/sam/admin-sidebar';
import { AdminTopbar } from '@/components/sam/admin-topbar';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { BreadcrumbItem } from '@/types';

interface AdminLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

/**
 * Shell for the cross-tenant super-admin console. Deliberately distinct from
 * OpsLayout (the tenant workspace): a tenant operator never sees this, and the
 * SaaS operator gets a console that is not scoped to a single tenant.
 */
export default function AdminLayout({
    children,
    breadcrumbs = [],
}: AdminLayoutProps) {
    const [mobileNavOpen, setMobileNavOpen] = useState(false);

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
                    <AdminSidebar />
                    <div className="flex min-w-0 flex-col overflow-hidden">
                        <AdminTopbar
                            breadcrumbs={breadcrumbs}
                            onOpenMobileNav={() => setMobileNavOpen(true)}
                        />
                        {children}
                    </div>
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
                    <AdminSidebar mobile />
                </SheetContent>
            </Sheet>
        </>
    );
}
