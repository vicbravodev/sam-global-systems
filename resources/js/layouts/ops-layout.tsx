import { useState } from 'react';
import { RealtimeBootstrap } from '@/components/realtime-bootstrap';
import { CommandPalette } from '@/components/sam/command-palette';
import { OpsSidebar } from '@/components/sam/ops-sidebar';
import { OpsTopbar } from '@/components/sam/ops-topbar';
import type { BreadcrumbItem } from '@/types';

interface OpsLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

// Temporary hardcoded values until navBadges is a shared Inertia prop
const navBadges = { inbox: 14, rules: 2, integrations: 1 };

export default function OpsLayout({
    children,
    breadcrumbs = [],
}: OpsLayoutProps) {
    const [commandOpen, setCommandOpen] = useState(false);

    return (
        <>
            <RealtimeBootstrap />
            <div className="grid h-dvh grid-cols-[auto_1fr] overflow-hidden">
                <OpsSidebar navBadges={navBadges} />
                <div className="flex min-w-0 flex-col overflow-hidden">
                    <OpsTopbar
                        breadcrumbs={breadcrumbs}
                        onOpenCommandPalette={() => setCommandOpen(true)}
                    />
                    {children}
                </div>
                <CommandPalette
                    open={commandOpen}
                    onClose={() => setCommandOpen(false)}
                />
            </div>
        </>
    );
}
