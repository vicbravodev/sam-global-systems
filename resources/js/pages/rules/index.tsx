import { Head, usePage } from '@inertiajs/react';
import {
    BellOff,
    CircleAlert,
    Plus,
    PowerOff,
    Scale,
    UserSearch,
} from 'lucide-react';
import { useState } from 'react';
import { PulseStat, PulseStrip } from '@/components/sam/pulse-strip';
import { DecisionRulesTab } from '@/components/sam/rules/decision-rules-tab';
import type { DecisionFilter } from '@/components/sam/rules/decision-rules-tab';
import { MappingRulesTab } from '@/components/sam/rules/mapping-rules-tab';
import type {
    DecisionSummary,
    RulesPageProps,
} from '@/components/sam/rules/types';
import { TabBar } from '@/components/sam/tab-bar';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

// La pestaña de ajustes por cuenta (TenantRuleOverride) sigue fuera de la
// navegación: se guardan pero nada los aplica al evaluar (ver
// ApplyTenantRuleOverrides). Controlador, rutas y datos se quedan para cuando
// exista consumidor real.
type TabKey = 'decision' | 'mapping';

function DecisionPulse({
    summary,
    filter,
    onFilter,
}: {
    summary: DecisionSummary;
    filter: DecisionFilter | null;
    onFilter: (filter: DecisionFilter | null) => void;
}) {
    const count = (...codes: string[]) =>
        codes.reduce((sum, code) => sum + (summary.byOutcome[code] ?? 0), 0);
    const toggle = (value: DecisionFilter) => () =>
        onFilter(filter === value ? null : value);
    const off = summary.total - summary.active;

    return (
        <PulseStrip>
            <PulseStat
                label="Encendidas"
                value={summary.active}
                hint={`de ${summary.total} ${summary.total === 1 ? 'regla' : 'reglas'}`}
                icon={Scale}
                tone="ok"
                onClick={toggle('active')}
                active={filter === 'active'}
            />
            <PulseStat
                label="Abren incidente"
                value={count('INCIDENT', 'ESCALATE')}
                hint="van directo a la bandeja"
                icon={CircleAlert}
                tone="critical"
                onClick={toggle('incident')}
                active={filter === 'incident'}
            />
            <PulseStat
                label="Piden revisión"
                value={count('REQUIRE_HUMAN_REVIEW')}
                hint="una persona decide"
                icon={UserSearch}
                tone="warn"
                onClick={toggle('review')}
                active={filter === 'review'}
            />
            <PulseStat
                label="Avisan o descartan"
                value={count('ALERT', 'LOG_ONLY', 'IGNORE')}
                hint="urgencia baja o sin aviso"
                icon={BellOff}
                onClick={toggle('other')}
                active={filter === 'other'}
            />
            <PulseStat
                label="Apagadas"
                value={off}
                hint={off === 0 ? 'todas funcionan' : 'no se revisan'}
                icon={PowerOff}
                tone={off > 0 ? 'warn' : 'neutral'}
                onClick={toggle('off')}
                active={filter === 'off'}
            />
        </PulseStrip>
    );
}

export default function RulesIndex() {
    const props = usePage().props as unknown as RulesPageProps;
    const [tab, setTab] = useState<TabKey>('decision');
    const [filter, setFilter] = useState<DecisionFilter | null>(null);
    const [creatingDecision, setCreatingDecision] = useState(false);
    const [creatingMapping, setCreatingMapping] = useState(false);

    const summary = props.decisionSummary;
    const canCreate =
        tab === 'decision'
            ? props.canManageDecisionRules
            : props.canManageMappingRules;

    return (
        <>
            <Head title="Reglas" />
            <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
                <PageHeader
                    title="Reglas"
                    description="Deciden qué pasa con cada evento: si abre un incidente, si pide revisión de una persona o si se ignora."
                    meta={
                        <span className="text-xs text-fg-3">
                            <span className="font-medium text-fg-1">
                                {summary.active}
                            </span>{' '}
                            {summary.active === 1
                                ? 'regla encendida'
                                : 'reglas encendidas'}
                        </span>
                    }
                    actions={
                        canCreate ? (
                            <Button
                                size="sm"
                                onClick={() =>
                                    tab === 'decision'
                                        ? setCreatingDecision(true)
                                        : setCreatingMapping(true)
                                }
                            >
                                <Plus className="size-3.5" />
                                {tab === 'decision'
                                    ? 'Nueva regla'
                                    : 'Nueva traducción'}
                            </Button>
                        ) : undefined
                    }
                    className="shrink-0 border-b border-border bg-surface-1 px-5 py-3"
                />

                {tab === 'decision' && (
                    <DecisionPulse
                        summary={summary}
                        filter={filter}
                        onFilter={setFilter}
                    />
                )}

                <TabBar
                    aria-label="Secciones de reglas"
                    value={tab}
                    onChange={(key) => setTab(key as TabKey)}
                    items={[
                        {
                            key: 'decision',
                            label: 'Reglas',
                            count: summary.total,
                        },
                        {
                            key: 'mapping',
                            label: 'Traducción de alertas',
                            count: props.mappingSummary.total,
                        },
                    ]}
                    className="shrink-0 px-5"
                />

                <div className="min-h-0 flex-1 overflow-y-auto">
                    {tab === 'decision' ? (
                        <DecisionRulesTab
                            rules={props.decisionRules}
                            rulesets={props.rulesets}
                            outcomes={props.outcomes}
                            fields={props.conditionFields}
                            canManage={props.canManageDecisionRules}
                            filter={filter}
                            onClearFilter={() => setFilter(null)}
                            creating={creatingDecision}
                            onCreatingChange={setCreatingDecision}
                        />
                    ) : (
                        <MappingRulesTab
                            rules={props.mappingRules}
                            summary={props.mappingSummary}
                            options={props.mappingOptions}
                            canManage={props.canManageMappingRules}
                            creating={creatingMapping}
                            onCreatingChange={setCreatingMapping}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

RulesIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Reglas',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/rules`
                : '/rules',
        },
    ],
});
