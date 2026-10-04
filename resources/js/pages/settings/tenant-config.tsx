import type { SharedPageProps } from '@inertiajs/core';
import { Head, usePage } from '@inertiajs/react';
import { Hourglass } from 'lucide-react';
import { lazy, Suspense } from 'react';
import { ReadOnlyNotice } from '@/components/sam/read-only-notice';
import { SettingsPage } from '@/components/sam/settings/settings-page';
import { AdvancedSection } from '@/components/sam/settings/tenant-config/advanced-section';
import { AiSection } from '@/components/sam/settings/tenant-config/ai-section';
import { AlertsSection } from '@/components/sam/settings/tenant-config/alerts-section';
import { BrandingSection } from '@/components/sam/settings/tenant-config/branding-section';
import { EmergenciesSection } from '@/components/sam/settings/tenant-config/emergencies-section';
import { HosSection } from '@/components/sam/settings/tenant-config/hos-section';
import { OnCallSection } from '@/components/sam/settings/tenant-config/on-call-section';
import type { TenantConfigProps } from '@/components/sam/settings/tenant-config/types';
import {
    COMPANY_SECTIONS,
    companySectionFromUrl,
} from '@/components/sam/settings/use-settings-nav';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import tenantConfigRoutes from '@/routes/tenant-config';

// Only the Escalamiento section uses the condition builder (and its
// comboboxes): it loads when that section is shown.
const EscalationSection = lazy(() =>
    import('@/components/sam/settings/tenant-config/escalation-section').then(
        (module) => ({ default: module.EscalationSection }),
    ),
);

/**
 * Configuración de la empresa (Roadmap F-TC). Una sola página Inertia con
 * secciones elegidas por `?seccion=` desde el índice lateral de Ajustes:
 * Emergencias, Respuesta de la IA, Avisos, Escalamiento, Guardias, Marca y
 * Avanzado (ajustes finos + historial de cambios).
 */
export default function TenantConfigPage(props: TenantConfigProps) {
    const page = usePage();
    const sectionKey = companySectionFromUrl(page.url);
    const section =
        COMPANY_SECTIONS.find((item) => item.key === sectionKey) ??
        COMPANY_SECTIONS[0];
    const editable =
        sectionKey === 'avisos'
            ? props.canManage || props.canManageChannels
            : sectionKey === 'hos'
              ? props.hos === null || props.hos.canManage
              : props.canManage;

    return (
        <>
            <Head title={`${section.title} · Configuración`} />
            <SettingsPage
                title={section.title}
                description={section.description}
            >
                {!editable ? <ReadOnlyNotice /> : null}

                {sectionKey === 'emergencias' && (
                    <EmergenciesSection
                        settings={props.settings}
                        canManage={props.canManage}
                    />
                )}
                {sectionKey === 'ia' && (
                    <AiSection
                        profile={props.aiProfile}
                        levels={props.aiProfileOptions.automationLevels}
                        canManage={props.canManage}
                    />
                )}
                {sectionKey === 'avisos' && (
                    <AlertsSection
                        settings={props.settings}
                        channels={props.channels}
                        policies={props.notificationPolicies}
                        channelTypes={props.channelTypes}
                        typeOptions={props.notificationTypeOptions ?? []}
                        canManage={props.canManage}
                        canManageChannels={props.canManageChannels}
                    />
                )}
                {sectionKey === 'escalamiento' && (
                    <Suspense
                        fallback={
                            <div
                                className="flex flex-col gap-4"
                                aria-busy="true"
                                aria-label="Cargando escalamiento"
                            >
                                <Skeleton className="h-5 w-48" />
                                <Skeleton className="h-64 w-full rounded-xl" />
                            </div>
                        }
                    >
                        <EscalationSection
                            configs={props.escalationConfigs}
                            conditionFields={props.escalationConditionFields}
                            channelTypes={props.channelTypes}
                            canManage={props.canManage}
                        />
                    </Suspense>
                )}
                {sectionKey === 'guardias' && (
                    <OnCallSection
                        profiles={props.scheduleProfiles}
                        users={props.recipientOptions.users}
                        canManage={props.canManage}
                    />
                )}
                {sectionKey === 'hos' &&
                    (props.hos ? (
                        <HosSection form={props.hos} />
                    ) : (
                        <EmptyState
                            className="py-8"
                            icon={Hourglass}
                            title="El monitoreo HOS no está activo"
                            description="Lo activa el equipo de SAM para empresas con choferes en Estados Unidos. Escríbenos si lo necesitas."
                        />
                    ))}
                {sectionKey === 'marca' && (
                    <BrandingSection
                        branding={props.branding}
                        canManage={props.canManage}
                    />
                )}
                {sectionKey === 'avanzado' && (
                    <AdvancedSection
                        settings={props.settings}
                        versions={props.versions}
                        canManage={props.canManage}
                    />
                )}
            </SettingsPage>
        </>
    );
}

TenantConfigPage.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'Configuración de la empresa',
            href: props.currentTeam
                ? tenantConfigRoutes.show.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
