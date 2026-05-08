import { RealtimeBootstrap } from '@/components/realtime-bootstrap';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <>
            <RealtimeBootstrap />
            <AppLayoutTemplate breadcrumbs={breadcrumbs}>
                {children}
            </AppLayoutTemplate>
        </>
    );
}
