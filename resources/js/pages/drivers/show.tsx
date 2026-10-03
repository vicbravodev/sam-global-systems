import type { SharedPageProps } from '@inertiajs/core';
import { Head, usePage } from '@inertiajs/react';
import { AssignmentsCard } from '@/components/sam/drivers/detail/assignments-card';
import { ContactsCard } from '@/components/sam/drivers/detail/contacts-card';
import { DocumentsCard } from '@/components/sam/drivers/detail/documents-card';
import { DriverHero } from '@/components/sam/drivers/detail/driver-hero';
import { ProfileCard } from '@/components/sam/drivers/detail/profile-card';
import { RiskCard } from '@/components/sam/drivers/detail/risk-card';
import { LinkedIncidentsCard } from '@/components/sam/linked-incidents-card';
import { RecentEventsCard } from '@/components/sam/recent-events-card';
import driverRoutes from '@/routes/drivers';
import type { DriverShowProps } from '@/types/drivers';

export default function DriverShow({
    driver,
    assignments,
    recentEvents,
    incidents,
    activity,
}: DriverShowProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;

    return (
        <>
            <Head title={`${driver.fullName} - Conductores`} />
            <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:p-6">
                <DriverHero driver={driver} teamSlug={teamSlug} />

                {/* Operación a la izquierda (riesgo, actividad, incidentes,
                    unidades); ficha a la derecha (perfil, contactos,
                    documentos). */}
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <div className="flex min-w-0 flex-col gap-4">
                        <RiskCard
                            risk={driver.riskProfile}
                            activity={activity ?? []}
                        />
                        <RecentEventsCard
                            events={recentEvents ?? []}
                            teamSlug={teamSlug}
                            subject="Este conductor"
                        />
                        <LinkedIncidentsCard
                            incidents={incidents ?? []}
                            teamSlug={teamSlug}
                            subject="Este conductor"
                        />
                        <AssignmentsCard
                            assignments={assignments}
                            teamSlug={teamSlug}
                        />
                    </div>
                    <div className="flex min-w-0 flex-col gap-4">
                        <ProfileCard driver={driver} />
                        <ContactsCard contacts={driver.contacts} />
                        <DocumentsCard documents={driver.documents} />
                    </div>
                </div>
            </div>
        </>
    );
}

DriverShow.layout = (props: SharedPageProps & Partial<DriverShowProps>) => ({
    breadcrumbs: [
        {
            title: 'Conductores',
            href: props.currentTeam
                ? driverRoutes.index.url(props.currentTeam.slug)
                : '#',
        },
        ...(props.driver
            ? [
                  {
                      title: props.driver.fullName,
                      href: props.currentTeam
                          ? driverRoutes.show.url([
                                props.currentTeam.slug,
                                props.driver.id,
                            ])
                          : '#',
                  },
              ]
            : []),
    ],
});
