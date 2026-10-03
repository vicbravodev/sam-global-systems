import { usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowRightLeft,
    FlaskConical,
    MoreHorizontal,
    Pencil,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { lazy, Suspense, useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { SegmentedFilter } from '@/components/sam/segmented-filter';
import { SeverityBadge } from '@/components/sam/severity-badge';
import type { Severity } from '@/components/sam/severity-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Switch } from '@/components/ui/switch';
import { deleteJson, putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { cn } from '@/lib/utils';
import rulesRoutes from '@/routes/rules';
import { mappingSource, RULE_SUBMIT } from './lib';
import { RuleTestDialog } from './rule-tester';
import type { MappingOptions, MappingRuleRow, MappingSummary } from './types';

// The editor (condition builder + comboboxes) loads on its first opening.
const MappingRuleSheet = lazy(() =>
    import('./mapping-rule-sheet').then((module) => ({
        default: module.MappingRuleSheet,
    })),
);

const SEVERITIES: Severity[] = ['critical', 'high', 'medium', 'low', 'info'];

function asSeverity(code: string | null): Severity | null {
    const value = code?.toLowerCase() as Severity | undefined;

    return value && SEVERITIES.includes(value) ? value : null;
}

type StateFilter = 'on' | 'off';

interface MappingRulesTabProps {
    rules: MappingRuleRow[];
    summary: MappingSummary;
    options: MappingOptions;
    canManage: boolean;
    creating: boolean;
    onCreatingChange: (creating: boolean) => void;
}

export function MappingRulesTab({
    rules,
    summary,
    options,
    canManage,
    creating,
    onCreatingChange,
}: MappingRulesTabProps) {
    const teamSlug = usePage().props.currentTeam?.slug ?? null;
    const [query, setQuery] = useState('');
    const [state, setState] = useState<StateFilter | null>(null);
    const [editId, setEditId] = useState<number | null>(null);
    const [testRule, setTestRule] = useState<MappingRuleRow | null>(null);
    const [deleteRule, setDeleteRule] = useState<MappingRuleRow | null>(null);
    const [togglingId, setTogglingId] = useState<number | null>(null);

    const rows = useMemo(
        () =>
            rules
                .map((rule) => ({ rule, source: mappingSource(rule) }))
                .sort(
                    (a, b) =>
                        (a.rule.mappedEventType ?? '').localeCompare(
                            b.rule.mappedEventType ?? '',
                            'es',
                        ) || a.source.title.localeCompare(b.source.title, 'es'),
                ),
        [rules],
    );

    const needle = query.trim().toLowerCase();
    const visible = rows.filter(({ rule, source }) => {
        if (state === 'on' && !rule.isActive) {
            return false;
        }

        if (state === 'off' && rule.isActive) {
            return false;
        }

        if (needle === '') {
            return true;
        }

        return [
            source.title,
            rule.externalEventType,
            rule.mappedEventType ?? '',
            rule.provider ?? '',
        ].some((text) => text.toLowerCase().includes(needle));
    });

    const providers = new Set(rules.map((rule) => rule.provider));
    const providerName =
        providers.size === 1 ? ([...providers][0] ?? 'el proveedor') : null;
    const editRule = rules.find((rule) => rule.id === editId) ?? null;
    const sheetOpen = creating || editRule !== null;
    // Mounted on first opening and kept, so closing still animates.
    const [sheetMounted, setSheetMounted] = useState(false);

    if (sheetOpen && !sheetMounted) {
        setSheetMounted(true);
    }

    const toggle = async (rule: MappingRuleRow, active: boolean) => {
        if (teamSlug === null || togglingId !== null) {
            return;
        }

        setTogglingId(rule.id);
        await submit(
            putJson(rulesRoutes.mapping.update.url([teamSlug, rule.id]), {
                is_active: active,
            }),
            active ? 'Traducción encendida.' : 'Traducción apagada.',
            RULE_SUBMIT,
        );
        setTogglingId(null);
    };

    const remove = async () => {
        if (teamSlug === null || deleteRule === null) {
            return;
        }

        const result = await submit(
            deleteJson(
                rulesRoutes.mapping.destroy.url([teamSlug, deleteRule.id]),
            ),
            'Traducción eliminada.',
            RULE_SUBMIT,
        );

        if (result.ok) {
            setDeleteRule(null);
        }
    };

    return (
        <div className="flex flex-col gap-4 px-4 py-4 sm:px-5">
            <p className="max-w-3xl rounded-md border border-border bg-surface-1 px-3 py-2.5 text-xs leading-relaxed text-fg-2">
                Cuando {providerName ?? 'un proveedor'} envía una alerta, SAM la
                traduce a uno de sus tipos de evento para saber qué es y qué tan
                grave es; después se le aplican las reglas.{' '}
                {canManage
                    ? 'Estas traducciones aplican a todas las cuentas.'
                    : 'Las mantiene el equipo de SAM: no necesitas cambiarlas.'}
            </p>

            <div className="flex flex-wrap items-center gap-2">
                <label className="flex h-8 items-center gap-1.5 rounded-md border border-border bg-background px-2.5 text-fg-3 focus-within:border-primary">
                    <Search className="size-3.5 shrink-0" aria-hidden="true" />
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Buscar alerta o tipo…"
                        aria-label="Buscar alerta o tipo"
                        className="w-44 bg-transparent text-xs text-fg-1 outline-none placeholder:text-fg-3"
                    />
                </label>
                <SegmentedFilter
                    aria-label="Filtrar por estado"
                    value={state}
                    onChange={(value) => setState(value as StateFilter | null)}
                    allLabel="Todas"
                    allCount={summary.total}
                    options={[
                        {
                            value: 'on',
                            label: 'Encendidas',
                            count: summary.active,
                            dot: 'bg-health-ok',
                        },
                        {
                            value: 'off',
                            label: 'Apagadas',
                            count: summary.total - summary.active,
                            dot: 'bg-fg-3',
                        },
                    ]}
                />
            </div>

            {rules.length === 0 ? (
                <EmptyState
                    icon={ArrowRightLeft}
                    title="Todavía no hay traducciones"
                    description="Sin traducciones, las alertas del proveedor no se pueden clasificar. Crea la primera para decirle a SAM cómo entender una alerta."
                    action={
                        canManage ? (
                            <Button
                                size="sm"
                                onClick={() => onCreatingChange(true)}
                            >
                                <Plus className="size-3.5" />
                                Nueva traducción
                            </Button>
                        ) : undefined
                    }
                    className="rounded-lg border border-dashed border-border"
                />
            ) : visible.length === 0 ? (
                <EmptyState
                    icon={Search}
                    title="Nada coincide con la búsqueda"
                    description="Prueba con otro nombre de alerta o de tipo de evento."
                    className="rounded-lg border border-dashed border-border"
                />
            ) : (
                <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-background">
                    {visible.map(({ rule, source }) => {
                        const severity = asSeverity(rule.effectiveSeverityCode);

                        return (
                            <li
                                key={rule.id}
                                className={cn(
                                    'flex items-center gap-3 px-4 py-3 sm:px-5',
                                    !rule.isActive && 'bg-surface-1/50',
                                )}
                            >
                                <div
                                    className={cn(
                                        'grid min-w-0 flex-1 grid-cols-1 items-center gap-x-4 gap-y-1.5 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]',
                                        !rule.isActive && 'opacity-70',
                                    )}
                                >
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <span className="sam-caps">
                                            Alerta de{' '}
                                            {rule.provider ?? 'proveedor'}
                                        </span>
                                        <span
                                            className="truncate text-sm text-fg-1"
                                            title={rule.externalEventType}
                                        >
                                            {source.title}
                                        </span>
                                        {source.detail && (
                                            <span className="truncate text-2xs text-fg-3">
                                                {source.detail}
                                            </span>
                                        )}
                                    </div>
                                    <ArrowRight
                                        className="hidden size-4 text-fg-3 sm:block"
                                        aria-label="se trata como"
                                    />
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <span className="sam-caps">
                                            Se trata como
                                        </span>
                                        <span className="flex flex-wrap items-center gap-1.5">
                                            <span className="text-sm font-medium text-fg-1">
                                                {rule.mappedEventType ?? '—'}
                                            </span>
                                            {severity && (
                                                <SeverityBadge
                                                    level={severity}
                                                />
                                            )}
                                        </span>
                                        {!rule.severityFromType && (
                                            <span className="text-2xs text-fg-3">
                                                Gravedad fijada por esta
                                                traducción
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex shrink-0 items-center gap-1">
                                    {canManage ? (
                                        <Switch
                                            checked={rule.isActive}
                                            disabled={togglingId === rule.id}
                                            onCheckedChange={(active) =>
                                                void toggle(rule, active)
                                            }
                                            aria-label={`${rule.isActive ? 'Apagar' : 'Encender'} traducción de ${source.title}`}
                                        />
                                    ) : (
                                        <span
                                            className={cn(
                                                'inline-flex items-center gap-1.5 text-xs whitespace-nowrap',
                                                rule.isActive
                                                    ? 'text-health-ok'
                                                    : 'text-fg-3',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'size-1.5 rounded-full',
                                                    rule.isActive
                                                        ? 'bg-health-ok'
                                                        : 'bg-fg-3',
                                                )}
                                                aria-hidden="true"
                                            />
                                            {rule.isActive
                                                ? 'Encendida'
                                                : 'Apagada'}
                                        </span>
                                    )}
                                    {(canManage || rule.hasConditions) && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    aria-label={`Acciones de ${source.title}`}
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {canManage && (
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setEditId(rule.id)
                                                        }
                                                    >
                                                        <Pencil className="size-3.5" />
                                                        Editar
                                                    </DropdownMenuItem>
                                                )}
                                                {rule.hasConditions && (
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setTestRule(rule)
                                                        }
                                                    >
                                                        <FlaskConical className="size-3.5" />
                                                        Probar
                                                    </DropdownMenuItem>
                                                )}
                                                {canManage && (
                                                    <>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onSelect={() =>
                                                                setDeleteRule(
                                                                    rule,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                            Eliminar
                                                        </DropdownMenuItem>
                                                    </>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}

            {canManage && sheetMounted && (
                <Suspense fallback={null}>
                    <MappingRuleSheet
                        open={sheetOpen}
                        onOpenChange={(open) => {
                            if (!open) {
                                onCreatingChange(false);
                                setEditId(null);
                            }
                        }}
                        rule={creating ? null : editRule}
                        options={options}
                    />
                </Suspense>
            )}

            {teamSlug !== null && (
                <RuleTestDialog
                    open={testRule !== null}
                    onOpenChange={(open) => !open && setTestRule(null)}
                    title={testRule ? mappingSource(testRule).title : ''}
                    endpoint={rulesRoutes.test.mapping.url(teamSlug)}
                    payload={() => ({
                        external_conditions_json: testRule?.conditions ?? {},
                    })}
                    fields={[]}
                    subject="alert"
                />
            )}

            <ConfirmDialog
                open={deleteRule !== null}
                onOpenChange={(open) => !open && setDeleteRule(null)}
                title="¿Eliminar esta traducción?"
                description="Aplica a todas las cuentas: las próximas alertas con ese nombre ya no se clasificarán con este tipo. Si solo quieres pausarla, apágala con el interruptor."
                confirmLabel="Eliminar traducción"
                onConfirm={remove}
            />
        </div>
    );
}
