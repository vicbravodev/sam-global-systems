import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    deleteJson,
    postJson,
    putJson,
    readErrorMessage,
} from '@/lib/sam-fetch';

// ---- Types ----

interface DecisionRuleRow {
    id: number;
    code: string;
    name: string;
    description: string | null;
    scope: string | null;
    priority: number;
    conditions: Record<string, unknown> | null;
    outcomeCode: string | null;
    outcomeId: number | null;
    stopProcessing: boolean;
    isActive: boolean;
    isGlobal: boolean;
    rulesetId: number;
    rulesetCode: string | null;
}

interface MappingRuleRow {
    id: number;
    providerId: number;
    provider: string | null;
    externalEventType: string;
    hasConditions: boolean;
    mappedEventTypeId: number;
    mappedEventType: string | null;
    mappedSeverity: string | null;
    priority: number;
    isActive: boolean;
}

interface OverrideRow {
    id: number;
    baseRuleCode: string;
    overrideType: string | null;
    config: Record<string, unknown> | null;
    reason: string | null;
    isActive: boolean;
}

interface Option {
    value: string;
    label: string;
}

interface RulesPageProps {
    decisionRules: DecisionRuleRow[];
    rulesets: {
        id: number;
        code: string;
        name: string;
        isDefault: boolean;
        isGlobal: boolean;
    }[];
    outcomes: { id: number; code: string; name: string }[];
    scopes: string[];
    mappingRules: MappingRuleRow[];
    mappingOptions: {
        providers: Option[];
        eventTypes: Option[];
        severities: Option[];
        categories: Option[];
    };
    overrides: OverrideRow[];
    overrideTypes: string[];
    canManageDecisionRules: boolean;
    canManageOverrides: boolean;
}

const TABS = [
    { key: 'decision', label: 'Reglas de decisión' },
    { key: 'mapping', label: 'Mapeo de eventos' },
    { key: 'overrides', label: 'Overrides del tenant' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

function useTeamBase(): string | null {
    const page = usePage();
    const slug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    return slug ? `/${slug}/rules` : null;
}

async function submit(
    promise: Promise<Response>,
    successMessage: string,
): Promise<boolean> {
    try {
        const response = await promise;

        if (response.ok || response.status === 201) {
            toast.success(successMessage);
            router.reload();

            return true;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para esta acción.');
        } else {
            toast.error(
                (await readErrorMessage(response)) ??
                    'No se pudo guardar la regla.',
            );
        }
    } catch {
        toast.error('Error de red. Vuelve a intentarlo.');
    }

    return false;
}

function parseJson(raw: string, label: string): Record<string, unknown> | null {
    try {
        return JSON.parse(raw) as Record<string, unknown>;
    } catch {
        toast.error(`JSON inválido en ${label}.`);

        return null;
    }
}

function ActiveBadge({ active }: { active: boolean }) {
    return (
        <Badge
            variant="outline"
            className={active ? 'text-severity-low' : 'text-fg-3'}
        >
            {active ? 'activa' : 'inactiva'}
        </Badge>
    );
}

// ---- Decision rules tab ----

function DecisionRulesTab({
    rules,
    rulesets,
    outcomes,
    scopes,
    canManage,
}: {
    rules: DecisionRuleRow[];
    rulesets: RulesPageProps['rulesets'];
    outcomes: RulesPageProps['outcomes'];
    scopes: string[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [expanded, setExpanded] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({
        code: '',
        name: '',
        scope: 'tenant',
        priority: '100',
        conditions:
            '{\n  "all": [\n    { "field": "event_type_code", "operator": "eq", "value": "panic_button" }\n  ]\n}',
        outcomeId: '',
        stopProcessing: false,
    });

    const toggleActive = (rule: DecisionRuleRow) => {
        if (base === null) {
            return;
        }

        void submit(
            putJson(`${base}/decision/${rule.id}`, {
                is_active: !rule.isActive,
            }),
            rule.isActive ? 'Regla desactivada.' : 'Regla activada.',
        );
    };

    const create = async () => {
        if (base === null) {
            return;
        }

        const conditions = parseJson(form.conditions, 'condiciones');

        if (conditions === null) {
            return;
        }

        const ruleset =
            rulesets.find((set) => !set.isGlobal && set.isDefault) ??
            rulesets[0];

        if (!ruleset) {
            toast.error('No hay ruleset disponible para crear reglas.');

            return;
        }

        const ok = await submit(
            postJson(`${base}/decision`, {
                ruleset_id: ruleset.id,
                code: form.code,
                name: form.name,
                scope: form.scope,
                priority: Number(form.priority) || 100,
                conditions_json: conditions,
                outcome_override:
                    form.outcomeId === '' ? null : Number(form.outcomeId),
                stop_processing: form.stopProcessing,
                is_active: true,
            }),
            'Regla creada.',
        );

        if (ok) {
            setCreating(false);
        }
    };

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center justify-between text-[13px] uppercase">
                        Reglas de decisión ({rules.length})
                        {canManage && (
                            <Button
                                size="sm"
                                variant={creating ? 'ghost' : 'outline'}
                                onClick={() => setCreating(!creating)}
                            >
                                {creating ? 'Cancelar' : 'Nueva regla'}
                            </Button>
                        )}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {creating && (
                        <div className="mb-4 flex flex-col gap-2 rounded-[6px] border border-border p-3">
                            <div className="flex flex-wrap gap-2">
                                <Input
                                    placeholder="code (ej. panic-vip)"
                                    value={form.code}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            code: e.target.value,
                                        })
                                    }
                                    className="w-48 font-mono text-[12px]"
                                />
                                <Input
                                    placeholder="Nombre"
                                    value={form.name}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            name: e.target.value,
                                        })
                                    }
                                    className="w-64 text-[12px]"
                                />
                                <select
                                    value={form.scope}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            scope: e.target.value,
                                        })
                                    }
                                    className="rounded-md border border-border bg-surface-1 px-2 text-[12px]"
                                >
                                    {scopes.map((scope) => (
                                        <option key={scope} value={scope}>
                                            {scope}
                                        </option>
                                    ))}
                                </select>
                                <Input
                                    type="number"
                                    placeholder="prioridad"
                                    value={form.priority}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            priority: e.target.value,
                                        })
                                    }
                                    className="w-24 text-[12px]"
                                />
                                <select
                                    value={form.outcomeId}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            outcomeId: e.target.value,
                                        })
                                    }
                                    className="rounded-md border border-border bg-surface-1 px-2 text-[12px]"
                                >
                                    <option value="">Outcome: ninguno</option>
                                    {outcomes.map((outcome) => (
                                        <option
                                            key={outcome.id}
                                            value={String(outcome.id)}
                                        >
                                            {outcome.code}
                                        </option>
                                    ))}
                                </select>
                                <label className="flex items-center gap-1 text-[12px] text-fg-2">
                                    <input
                                        type="checkbox"
                                        checked={form.stopProcessing}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                stopProcessing:
                                                    e.target.checked,
                                            })
                                        }
                                    />
                                    stop
                                </label>
                            </div>
                            <Label className="text-[12px]">
                                Condiciones (all/any · operadores: eq, neq, gt,
                                gte, lt, lte, in, not_in, contains, is_null,
                                is_not_null)
                            </Label>
                            <textarea
                                value={form.conditions}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        conditions: e.target.value,
                                    })
                                }
                                rows={6}
                                spellCheck={false}
                                className="rounded-md border border-border bg-surface-2 p-2 font-mono text-[11px] text-fg-2"
                            />
                            <div>
                                <Button size="sm" onClick={create}>
                                    Crear regla
                                </Button>
                            </div>
                        </div>
                    )}

                    {rules.length === 0 ? (
                        <p className="text-[12px] text-fg-3">
                            Sin reglas de decisión.
                        </p>
                    ) : (
                        <table className="w-full text-left text-[12px]">
                            <thead className="text-[11px] text-fg-3 uppercase">
                                <tr>
                                    <th className="py-1.5 pr-4">Prio</th>
                                    <th className="py-1.5 pr-4">Código</th>
                                    <th className="py-1.5 pr-4">Nombre</th>
                                    <th className="py-1.5 pr-4">Outcome</th>
                                    <th className="py-1.5 pr-4">Origen</th>
                                    <th className="py-1.5 pr-4">Estado</th>
                                    <th className="py-1.5 pr-4" />
                                </tr>
                            </thead>
                            <tbody>
                                {rules.map((rule) => (
                                    <>
                                        <tr
                                            key={rule.id}
                                            onClick={() =>
                                                setExpanded(
                                                    expanded === rule.id
                                                        ? null
                                                        : rule.id,
                                                )
                                            }
                                            className="cursor-pointer border-t border-border/50 text-fg-2 hover:bg-surface-1"
                                        >
                                            <td className="py-2 pr-4 tabular-nums">
                                                {rule.priority}
                                            </td>
                                            <td className="py-2 pr-4 font-mono text-[11px]">
                                                {rule.code}
                                            </td>
                                            <td className="py-2 pr-4 text-fg-1">
                                                {rule.name}
                                                {rule.stopProcessing && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-1.5 text-[10px] text-fg-3"
                                                    >
                                                        stop
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2 pr-4 font-mono text-[11px]">
                                                {rule.outcomeCode ?? '—'}
                                            </td>
                                            <td className="py-2 pr-4">
                                                {rule.isGlobal
                                                    ? 'global'
                                                    : 'tenant'}
                                            </td>
                                            <td className="py-2 pr-4">
                                                <ActiveBadge
                                                    active={rule.isActive}
                                                />
                                            </td>
                                            <td className="py-2 text-right">
                                                {canManage &&
                                                    !rule.isGlobal && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                toggleActive(
                                                                    rule,
                                                                );
                                                            }}
                                                        >
                                                            {rule.isActive
                                                                ? 'Desactivar'
                                                                : 'Activar'}
                                                        </Button>
                                                    )}
                                            </td>
                                        </tr>
                                        {expanded === rule.id && (
                                            <tr key={`${rule.id}-detail`}>
                                                <td
                                                    colSpan={7}
                                                    className="bg-surface-1 px-3 py-2"
                                                >
                                                    <pre className="overflow-auto rounded bg-surface-2 p-2 font-mono text-[11px] text-fg-2">
                                                        {JSON.stringify(
                                                            rule.conditions ??
                                                                {},
                                                            null,
                                                            2,
                                                        )}
                                                    </pre>
                                                </td>
                                            </tr>
                                        )}
                                    </>
                                ))}
                            </tbody>
                        </table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

// ---- Mapping rules tab ----

function MappingRulesTab({
    rules,
    options,
    canManage,
}: {
    rules: MappingRuleRow[];
    options: RulesPageProps['mappingOptions'];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({
        providerId: '',
        externalEventType: '',
        eventTypeId: '',
        severityId: '',
        priority: '100',
    });

    const toggleActive = (rule: MappingRuleRow) => {
        if (base === null) {
            return;
        }

        void submit(
            putJson(`${base}/mapping/${rule.id}`, {
                is_active: !rule.isActive,
            }),
            rule.isActive ? 'Regla desactivada.' : 'Regla activada.',
        );
    };

    const create = async () => {
        if (base === null) {
            return;
        }

        if (
            form.providerId === '' ||
            form.externalEventType === '' ||
            form.eventTypeId === ''
        ) {
            toast.error('Proveedor, evento externo y tipo son obligatorios.');

            return;
        }

        const ok = await submit(
            postJson(`${base}/mapping`, {
                provider_id: Number(form.providerId),
                external_event_type: form.externalEventType,
                mapped_event_type_id: Number(form.eventTypeId),
                mapped_severity_id:
                    form.severityId === '' ? null : Number(form.severityId),
                priority: Number(form.priority) || 100,
                is_active: true,
            }),
            'Regla de mapeo creada.',
        );

        if (ok) {
            setCreating(false);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-[13px] uppercase">
                    Reglas de mapeo ({rules.length})
                    {canManage && (
                        <Button
                            size="sm"
                            variant={creating ? 'ghost' : 'outline'}
                            onClick={() => setCreating(!creating)}
                        >
                            {creating ? 'Cancelar' : 'Nueva regla'}
                        </Button>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {creating && (
                    <div className="mb-4 flex flex-wrap items-center gap-2 rounded-[6px] border border-border p-3">
                        <select
                            value={form.providerId}
                            onChange={(e) =>
                                setForm({ ...form, providerId: e.target.value })
                            }
                            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[12px]"
                        >
                            <option value="">Proveedor…</option>
                            {options.providers.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <Input
                            placeholder="evento externo (behaviorLabel)"
                            value={form.externalEventType}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    externalEventType: e.target.value,
                                })
                            }
                            className="w-60 font-mono text-[12px]"
                        />
                        <span className="text-fg-3">→</span>
                        <select
                            value={form.eventTypeId}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    eventTypeId: e.target.value,
                                })
                            }
                            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[12px]"
                        >
                            <option value="">Tipo de evento…</option>
                            {options.eventTypes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <select
                            value={form.severityId}
                            onChange={(e) =>
                                setForm({ ...form, severityId: e.target.value })
                            }
                            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[12px]"
                        >
                            <option value="">Severidad: default</option>
                            {options.severities.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <Input
                            type="number"
                            value={form.priority}
                            onChange={(e) =>
                                setForm({ ...form, priority: e.target.value })
                            }
                            className="w-24 text-[12px]"
                        />
                        <Button size="sm" onClick={create}>
                            Crear
                        </Button>
                    </div>
                )}

                {rules.length === 0 ? (
                    <p className="text-[12px] text-fg-3">
                        Sin reglas de mapeo.
                    </p>
                ) : (
                    <table className="w-full text-left text-[12px]">
                        <thead className="text-[11px] text-fg-3 uppercase">
                            <tr>
                                <th className="py-1.5 pr-4">Prio</th>
                                <th className="py-1.5 pr-4">Proveedor</th>
                                <th className="py-1.5 pr-4">Evento externo</th>
                                <th className="py-1.5 pr-4">→ Tipo</th>
                                <th className="py-1.5 pr-4">Severidad</th>
                                <th className="py-1.5 pr-4">Estado</th>
                                <th className="py-1.5 pr-4" />
                            </tr>
                        </thead>
                        <tbody>
                            {rules.map((rule) => (
                                <tr
                                    key={rule.id}
                                    className="border-t border-border/50 text-fg-2"
                                >
                                    <td className="py-2 pr-4 tabular-nums">
                                        {rule.priority}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {rule.provider}
                                    </td>
                                    <td className="py-2 pr-4 font-mono text-[11px]">
                                        {rule.externalEventType}
                                        {rule.hasConditions && (
                                            <Badge
                                                variant="outline"
                                                className="ml-1 text-[10px] text-fg-3"
                                            >
                                                cond
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="py-2 pr-4 text-fg-1">
                                        {rule.mappedEventType}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {rule.mappedSeverity ?? 'default'}
                                    </td>
                                    <td className="py-2 pr-4">
                                        <ActiveBadge active={rule.isActive} />
                                    </td>
                                    <td className="py-2 text-right">
                                        {canManage && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    toggleActive(rule)
                                                }
                                            >
                                                {rule.isActive
                                                    ? 'Desactivar'
                                                    : 'Activar'}
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Overrides tab ----

function OverridesTab({
    overrides,
    overrideTypes,
    decisionRuleCodes,
    canManage,
}: {
    overrides: OverrideRow[];
    overrideTypes: string[];
    decisionRuleCodes: string[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [creating, setCreating] = useState(false);
    const [form, setForm] = useState({
        baseRuleCode: '',
        overrideType: 'force_human_review',
        config: '{}',
        reason: '',
    });

    const create = async () => {
        if (base === null) {
            return;
        }

        const config = parseJson(form.config, 'configuración del override');

        if (config === null || form.baseRuleCode === '') {
            if (form.baseRuleCode === '') {
                toast.error('Indica el código de la regla base.');
            }

            return;
        }

        const ok = await submit(
            postJson(`${base}/overrides`, {
                base_rule_code: form.baseRuleCode,
                override_type: form.overrideType,
                override_config: config,
                reason: form.reason === '' ? null : form.reason,
                is_active: true,
            }),
            'Override creado.',
        );

        if (ok) {
            setCreating(false);
        }
    };

    const remove = (override: OverrideRow) => {
        if (base === null) {
            return;
        }

        void submit(
            deleteJson(`${base}/overrides/${override.id}`),
            'Override eliminado.',
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-[13px] uppercase">
                    Overrides del tenant ({overrides.length})
                    {canManage && (
                        <Button
                            size="sm"
                            variant={creating ? 'ghost' : 'outline'}
                            onClick={() => setCreating(!creating)}
                        >
                            {creating ? 'Cancelar' : 'Nuevo override'}
                        </Button>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {creating && (
                    <div className="mb-4 flex flex-col gap-2 rounded-[6px] border border-border p-3">
                        <div className="flex flex-wrap gap-2">
                            <Input
                                placeholder="código de regla base"
                                list="rule-codes"
                                value={form.baseRuleCode}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        baseRuleCode: e.target.value,
                                    })
                                }
                                className="w-64 font-mono text-[12px]"
                            />
                            <datalist id="rule-codes">
                                {decisionRuleCodes.map((code) => (
                                    <option key={code} value={code} />
                                ))}
                            </datalist>
                            <select
                                value={form.overrideType}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        overrideType: e.target.value,
                                    })
                                }
                                className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-[12px]"
                            >
                                {overrideTypes.map((type) => (
                                    <option key={type} value={type}>
                                        {type}
                                    </option>
                                ))}
                            </select>
                            <Input
                                placeholder="motivo (opcional)"
                                value={form.reason}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        reason: e.target.value,
                                    })
                                }
                                className="w-72 text-[12px]"
                            />
                        </div>
                        <Label className="text-[12px]">
                            Configuración (JSON)
                        </Label>
                        <textarea
                            value={form.config}
                            onChange={(e) =>
                                setForm({ ...form, config: e.target.value })
                            }
                            rows={4}
                            spellCheck={false}
                            className="rounded-md border border-border bg-surface-2 p-2 font-mono text-[11px] text-fg-2"
                        />
                        <div>
                            <Button size="sm" onClick={create}>
                                Crear override
                            </Button>
                        </div>
                    </div>
                )}

                {overrides.length === 0 ? (
                    <p className="text-[12px] text-fg-3">
                        Sin overrides — las reglas base aplican tal cual.
                    </p>
                ) : (
                    <table className="w-full text-left text-[12px]">
                        <thead className="text-[11px] text-fg-3 uppercase">
                            <tr>
                                <th className="py-1.5 pr-4">Regla base</th>
                                <th className="py-1.5 pr-4">Tipo</th>
                                <th className="py-1.5 pr-4">Config</th>
                                <th className="py-1.5 pr-4">Motivo</th>
                                <th className="py-1.5 pr-4">Estado</th>
                                <th className="py-1.5 pr-4" />
                            </tr>
                        </thead>
                        <tbody>
                            {overrides.map((override) => (
                                <tr
                                    key={override.id}
                                    className="border-t border-border/50 text-fg-2"
                                >
                                    <td className="py-2 pr-4 font-mono text-[11px]">
                                        {override.baseRuleCode}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {override.overrideType}
                                    </td>
                                    <td className="py-2 pr-4 font-mono text-[11px]">
                                        {JSON.stringify(override.config)}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {override.reason ?? '—'}
                                    </td>
                                    <td className="py-2 pr-4">
                                        <ActiveBadge
                                            active={override.isActive}
                                        />
                                    </td>
                                    <td className="py-2 text-right">
                                        {canManage && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => remove(override)}
                                            >
                                                Eliminar
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}

// ---- Page ----

export default function RulesIndex() {
    const page = usePage();
    const props = page.props as unknown as RulesPageProps;
    const [tab, setTab] = useState<TabKey>('decision');

    return (
        <>
            <Head title="Reglas" />
            <div className="flex flex-col gap-4 p-5">
                <div>
                    <h1 className="text-[16px] font-semibold text-fg-1">
                        Reglas
                    </h1>
                    <p className="text-[12px] text-fg-3">
                        Motor de decisiones, mapeo de eventos del proveedor y
                        overrides del tenant.
                    </p>
                </div>

                <div className="flex flex-wrap gap-1 border-b border-border">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`px-3 py-2 text-[13px] transition-colors ${
                                tab === item.key
                                    ? 'border-b-2 border-primary font-medium text-fg-1'
                                    : 'text-fg-3 hover:text-fg-1'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>

                {tab === 'decision' && (
                    <DecisionRulesTab
                        rules={props.decisionRules}
                        rulesets={props.rulesets}
                        outcomes={props.outcomes}
                        scopes={props.scopes}
                        canManage={props.canManageDecisionRules}
                    />
                )}
                {tab === 'mapping' && (
                    <MappingRulesTab
                        rules={props.mappingRules}
                        options={props.mappingOptions}
                        canManage={props.canManageDecisionRules}
                    />
                )}
                {tab === 'overrides' && (
                    <OverridesTab
                        overrides={props.overrides}
                        overrideTypes={props.overrideTypes}
                        decisionRuleCodes={props.decisionRules.map(
                            (rule) => rule.code,
                        )}
                        canManage={props.canManageOverrides}
                    />
                )}
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
