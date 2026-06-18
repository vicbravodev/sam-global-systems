import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import {
    ConditionBuilder,
    RuleTestPanel,
} from '@/components/sam/condition-builder';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import {
    deleteJson,
    postJson,
    putJson,
    readErrorPayload,
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
    outcomeLabel: string | null;
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
    outcomes: { id: number; code: string; name: string; label: string }[];
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
    conditionFields: ConditionFieldDef[];
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

interface SubmitResult {
    ok: boolean;
    /** Primer mensaje por campo del `errors` de Laravel (D-04). */
    fieldErrors: Record<string, string>;
}

async function submit(
    promise: Promise<Response>,
    successMessage: string,
): Promise<SubmitResult> {
    try {
        const response = await promise;

        if (response.ok || response.status === 201) {
            toast.success(successMessage);
            router.reload();

            return { ok: true, fieldErrors: {} };
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para esta acción.');

            return { ok: false, fieldErrors: {} };
        }

        const { message, fieldErrors } = await readErrorPayload(response);

        toast.error(
            Object.values(fieldErrors)[0] ??
                message ??
                'No se pudo guardar la regla.',
        );

        return { ok: false, fieldErrors };
    } catch {
        toast.error('Error de red. Vuelve a intentarlo.');
    }

    return { ok: false, fieldErrors: {} };
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

// ---- Decision rule conditions editor (expanded row) ----

function RuleConditionsEditor({
    rule,
    fields,
    outcomes,
    canEdit,
}: {
    rule: DecisionRuleRow;
    fields: ConditionFieldDef[];
    outcomes: RulesPageProps['outcomes'];
    canEdit: boolean;
}) {
    const base = useTeamBase();
    const [conditions, setConditions] = useState<Record<string, unknown>>(
        rule.conditions ?? {},
    );
    // D-10: nombre/descripción/prioridad/outcome ahora son editables; `code`
    // sigue siendo inmutable (identidad de la regla) y se avisa en la UI.
    const [meta, setMeta] = useState({
        name: rule.name,
        description: rule.description ?? '',
        priority: String(rule.priority),
        outcomeId: rule.outcomeId === null ? '' : String(rule.outcomeId),
    });
    const [saving, setSaving] = useState(false);
    const [jsonError, setJsonError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const metaDirty =
        meta.name !== rule.name ||
        meta.description !== (rule.description ?? '') ||
        meta.priority !== String(rule.priority) ||
        meta.outcomeId !==
            (rule.outcomeId === null ? '' : String(rule.outcomeId));
    const dirty =
        metaDirty ||
        JSON.stringify(conditions) !== JSON.stringify(rule.conditions ?? {});

    const save = async () => {
        if (base === null || saving) {
            return;
        }

        // D-05: con JSON inválido en modo avanzado no se guarda nada (antes
        // se mandaban silenciosamente las condiciones previas del builder).
        if (jsonError !== null) {
            setErrors({
                conditions_json: 'JSON inválido: corrígelo antes de guardar.',
            });

            return;
        }

        if (meta.name.trim() === '') {
            setErrors({ name: 'El nombre es obligatorio.' });

            return;
        }

        setErrors({});
        setSaving(true);

        const result = await submit(
            putJson(`${base}/decision/${rule.id}`, {
                name: meta.name,
                description: meta.description === '' ? null : meta.description,
                priority: Number(meta.priority) || 0,
                outcome_override:
                    meta.outcomeId === '' ? null : Number(meta.outcomeId),
                conditions_json: conditions,
            }),
            'Regla guardada.',
        );

        if (!result.ok) {
            setErrors(result.fieldErrors);
        }

        setSaving(false);
    };

    return (
        <div className="flex flex-col gap-3">
            {canEdit ? (
                <div className="flex flex-wrap gap-2">
                    <div className="flex flex-col gap-1">
                        <Label className="text-2xs text-fg-3 uppercase">
                            Código (no editable)
                        </Label>
                        <Input
                            value={rule.code}
                            disabled
                            title="El código identifica la regla y no se puede cambiar."
                            className="w-48 font-mono text-xs"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label className="text-2xs text-fg-3 uppercase">
                            Nombre
                        </Label>
                        <Input
                            value={meta.name}
                            aria-invalid={Boolean(errors.name)}
                            onChange={(e) =>
                                setMeta({ ...meta, name: e.target.value })
                            }
                            className="w-64 text-xs"
                        />
                        <InputError
                            message={errors.name}
                            className="max-w-64 text-xs"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label className="text-2xs text-fg-3 uppercase">
                            Prioridad
                        </Label>
                        <Input
                            type="number"
                            value={meta.priority}
                            aria-invalid={Boolean(errors.priority)}
                            onChange={(e) =>
                                setMeta({ ...meta, priority: e.target.value })
                            }
                            className="w-24 text-xs"
                        />
                        <InputError
                            message={errors.priority}
                            className="text-xs"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label className="text-2xs text-fg-3 uppercase">
                            Outcome
                        </Label>
                        <select
                            value={meta.outcomeId}
                            onChange={(e) =>
                                setMeta({ ...meta, outcomeId: e.target.value })
                            }
                            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs"
                        >
                            <option value="">Outcome: ninguno</option>
                            {outcomes.map((outcome) => (
                                <option
                                    key={outcome.id}
                                    value={String(outcome.id)}
                                >
                                    {outcome.label ?? outcome.code}
                                </option>
                            ))}
                        </select>
                        <InputError
                            message={errors.outcome_override}
                            className="text-xs"
                        />
                    </div>
                    <div className="flex w-full flex-col gap-1">
                        <Label className="text-2xs text-fg-3 uppercase">
                            Descripción
                        </Label>
                        <Input
                            value={meta.description}
                            onChange={(e) =>
                                setMeta({
                                    ...meta,
                                    description: e.target.value,
                                })
                            }
                            className="max-w-xl text-xs"
                        />
                    </div>
                </div>
            ) : (
                <p className="text-2xs text-fg-3">
                    Regla global: solo lectura para tu tenant. Usa un override
                    del tenant para ajustar su comportamiento.
                </p>
            )}
            <Label className="text-2xs text-fg-3 uppercase">Condiciones</Label>
            <ConditionBuilder
                variant="tree"
                fields={fields}
                value={conditions}
                onChange={(next) => {
                    setConditions(next);
                    setErrors({});
                }}
                onJsonErrorChange={setJsonError}
                disabled={!canEdit}
            />
            <InputError message={errors.conditions_json} className="text-xs" />
            {base !== null && (
                <RuleTestPanel
                    endpoint={`${base}/test-decision`}
                    payload={() => ({ conditions_json: conditions })}
                />
            )}
            {canEdit && (
                <div>
                    <Button
                        size="sm"
                        onClick={save}
                        disabled={!dirty || saving}
                    >
                        {saving ? 'Guardando…' : 'Guardar regla'}
                    </Button>
                </div>
            )}
        </div>
    );
}

// ---- Decision rules tab ----

function DecisionRulesTab({
    rules,
    rulesets,
    outcomes,
    scopes,
    conditionFields,
    canManage,
}: {
    rules: DecisionRuleRow[];
    rulesets: RulesPageProps['rulesets'];
    outcomes: RulesPageProps['outcomes'];
    scopes: string[];
    conditionFields: ConditionFieldDef[];
    canManage: boolean;
}) {
    const base = useTeamBase();
    const [expanded, setExpanded] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [jsonError, setJsonError] = useState<string | null>(null);
    const [form, setForm] = useState({
        code: '',
        name: '',
        scope: 'tenant',
        priority: '100',
        outcomeId: '',
        stopProcessing: false,
    });
    const [conditions, setConditions] = useState<Record<string, unknown>>({
        all: [
            {
                field: 'event_type_code',
                operator: 'eq',
                value: 'panic_button',
            },
        ],
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
        // D-01: guard contra doble click — no se emite un segundo POST
        // mientras el primero sigue en vuelo.
        if (base === null || submitting) {
            return;
        }

        // D-05: el JSON inválido del modo avanzado bloquea el submit en vez
        // de mandar silenciosamente las condiciones previas del builder.
        if (jsonError !== null) {
            setErrors({
                conditions_json:
                    'JSON inválido: corrígelo antes de crear la regla.',
            });

            return;
        }

        const ruleset =
            rulesets.find((set) => !set.isGlobal && set.isDefault) ??
            rulesets[0];

        if (!ruleset) {
            toast.error('No hay ruleset disponible para crear reglas.');

            return;
        }

        setErrors({});
        setSubmitting(true);

        const result = await submit(
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

        setSubmitting(false);

        if (result.ok) {
            setCreating(false);
        } else {
            setErrors(result.fieldErrors);
        }
    };

    // Errores que no corresponden a ningún campo visible del form (p. ej.
    // ruleset_id) — se muestran como bloque persistente, no solo toast.
    const knownFields = [
        'code',
        'name',
        'scope',
        'priority',
        'outcome_override',
        'conditions_json',
    ];
    const otherErrors = Object.entries(errors).filter(
        ([field]) => !knownFields.includes(field),
    );

    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center justify-between text-sm uppercase">
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
                        <div className="mb-4 flex flex-col gap-2 rounded-md border border-border p-3">
                            <div className="flex flex-wrap gap-2">
                                <div className="flex flex-col gap-1">
                                    <Input
                                        placeholder="code (ej. panic-vip)"
                                        value={form.code}
                                        aria-invalid={Boolean(errors.code)}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                code: e.target.value,
                                            })
                                        }
                                        className="w-48 font-mono text-xs"
                                    />
                                    <InputError
                                        message={errors.code}
                                        className="max-w-48 text-xs"
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Input
                                        placeholder="Nombre"
                                        value={form.name}
                                        aria-invalid={Boolean(errors.name)}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                name: e.target.value,
                                            })
                                        }
                                        className="w-64 text-xs"
                                    />
                                    <InputError
                                        message={errors.name}
                                        className="max-w-64 text-xs"
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <select
                                        value={form.scope}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                scope: e.target.value,
                                            })
                                        }
                                        className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs"
                                    >
                                        {scopes.map((scope) => (
                                            <option key={scope} value={scope}>
                                                {scope}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.scope}
                                        className="text-xs"
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Input
                                        type="number"
                                        placeholder="prioridad"
                                        value={form.priority}
                                        aria-invalid={Boolean(errors.priority)}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                priority: e.target.value,
                                            })
                                        }
                                        className="w-24 text-xs"
                                    />
                                    <InputError
                                        message={errors.priority}
                                        className="text-xs"
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <select
                                        value={form.outcomeId}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                outcomeId: e.target.value,
                                            })
                                        }
                                        className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs"
                                    >
                                        <option value="">
                                            Outcome: ninguno
                                        </option>
                                        {outcomes.map((outcome) => (
                                            <option
                                                key={outcome.id}
                                                value={String(outcome.id)}
                                            >
                                                {outcome.label ?? outcome.code}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.outcome_override}
                                        className="text-xs"
                                    />
                                </div>
                                <label className="flex items-center gap-1 text-xs text-fg-2">
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
                            <Label className="text-xs">Condiciones</Label>
                            <ConditionBuilder
                                variant="tree"
                                fields={conditionFields}
                                value={conditions}
                                onChange={setConditions}
                                onJsonErrorChange={setJsonError}
                            />
                            <InputError
                                message={errors.conditions_json}
                                className="text-xs"
                            />
                            {base !== null && (
                                <RuleTestPanel
                                    endpoint={`${base}/test-decision`}
                                    payload={() => ({
                                        conditions_json: conditions,
                                    })}
                                />
                            )}
                            {otherErrors.length > 0 && (
                                <ul className="flex flex-col gap-0.5">
                                    {otherErrors.map(([field, message]) => (
                                        <li key={field}>
                                            <InputError
                                                message={message}
                                                className="text-xs"
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div>
                                <Button
                                    size="sm"
                                    onClick={create}
                                    disabled={submitting}
                                >
                                    {submitting ? 'Creando…' : 'Crear regla'}
                                </Button>
                            </div>
                        </div>
                    )}

                    {rules.length === 0 ? (
                        <p className="text-xs text-fg-3">
                            Sin reglas de decisión.
                        </p>
                    ) : (
                        <table className="w-full text-left text-xs">
                            <thead className="text-2xs text-fg-3 uppercase">
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
                                            <td className="py-2 pr-4 font-mono text-2xs">
                                                {rule.code}
                                            </td>
                                            <td className="py-2 pr-4 text-fg-1">
                                                {rule.name}
                                                {rule.stopProcessing && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-1.5 text-3xs text-fg-3"
                                                    >
                                                        stop
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2 pr-4 font-mono text-2xs">
                                                {rule.outcomeLabel ??
                                                    rule.outcomeCode ??
                                                    '—'}
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
                                                    className="bg-surface-1 px-3 py-3"
                                                >
                                                    <RuleConditionsEditor
                                                        rule={rule}
                                                        fields={conditionFields}
                                                        outcomes={outcomes}
                                                        canEdit={
                                                            canManage &&
                                                            !rule.isGlobal
                                                        }
                                                    />
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
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [form, setForm] = useState({
        providerId: '',
        externalEventType: '',
        eventTypeId: '',
        severityId: '',
        priority: '100',
    });
    const [conditions, setConditions] = useState<Record<string, unknown>>({});

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
        // D-01: guard contra doble click.
        if (base === null || submitting) {
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

        setErrors({});
        setSubmitting(true);

        const result = await submit(
            postJson(`${base}/mapping`, {
                provider_id: Number(form.providerId),
                external_event_type: form.externalEventType,
                external_conditions_json:
                    Object.keys(conditions).length > 0 ? conditions : null,
                mapped_event_type_id: Number(form.eventTypeId),
                mapped_severity_id:
                    form.severityId === '' ? null : Number(form.severityId),
                priority: Number(form.priority) || 100,
                is_active: true,
            }),
            'Regla de mapeo creada.',
        );

        setSubmitting(false);

        if (result.ok) {
            setCreating(false);
        } else {
            setErrors(result.fieldErrors);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-sm uppercase">
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
                    <div className="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-border p-3">
                        <select
                            value={form.providerId}
                            onChange={(e) =>
                                setForm({ ...form, providerId: e.target.value })
                            }
                            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs"
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
                            className="w-60 font-mono text-xs"
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
                            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs"
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
                            className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs"
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
                            className="w-24 text-xs"
                        />
                        <div className="w-full">
                            <Label className="mb-2 block text-xs">
                                Condiciones sobre el payload (opcional)
                            </Label>
                            <ConditionBuilder
                                variant="flat-equality"
                                fields={[]}
                                allowUnknownFields
                                value={conditions}
                                onChange={setConditions}
                            />
                            {base !== null &&
                                Object.keys(conditions).length > 0 && (
                                    <RuleTestPanel
                                        className="mt-2"
                                        endpoint={`${base}/test-mapping`}
                                        payload={() => ({
                                            external_conditions_json:
                                                conditions,
                                        })}
                                    />
                                )}
                        </div>
                        {Object.keys(errors).length > 0 && (
                            <ul className="flex w-full flex-col gap-0.5">
                                {Object.entries(errors).map(
                                    ([field, message]) => (
                                        <li key={field}>
                                            <InputError
                                                message={message}
                                                className="text-xs"
                                            />
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                        <Button
                            size="sm"
                            onClick={create}
                            disabled={submitting}
                        >
                            {submitting ? 'Creando…' : 'Crear'}
                        </Button>
                    </div>
                )}

                {rules.length === 0 ? (
                    <p className="text-xs text-fg-3">Sin reglas de mapeo.</p>
                ) : (
                    <table className="w-full text-left text-xs">
                        <thead className="text-2xs text-fg-3 uppercase">
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
                                    <td className="py-2 pr-4 font-mono text-2xs">
                                        {rule.externalEventType}
                                        {rule.hasConditions && (
                                            <Badge
                                                variant="outline"
                                                className="ml-1 text-3xs text-fg-3"
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
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [deleting, setDeleting] = useState<OverrideRow | null>(null);
    const [form, setForm] = useState({
        baseRuleCode: '',
        overrideType: 'force_human_review',
        config: '{}',
        reason: '',
    });

    const create = async () => {
        // D-01: guard contra doble click.
        if (base === null || submitting) {
            return;
        }

        const nextErrors: Record<string, string> = {};
        let config: Record<string, unknown> | null = null;

        // D-05: JSON inválido bloquea el submit con error inline.
        try {
            const parsed = JSON.parse(form.config) as unknown;

            if (
                parsed === null ||
                typeof parsed !== 'object' ||
                Array.isArray(parsed)
            ) {
                nextErrors.override_config =
                    'La configuración debe ser un objeto JSON.';
            } else {
                config = parsed as Record<string, unknown>;
            }
        } catch {
            nextErrors.override_config =
                'JSON inválido: revisa la sintaxis de la configuración.';
        }

        if (form.baseRuleCode === '') {
            nextErrors.base_rule_code = 'Indica el código de la regla base.';
        }

        if (config === null || Object.keys(nextErrors).length > 0) {
            setErrors(nextErrors);

            return;
        }

        setErrors({});
        setSubmitting(true);

        const result = await submit(
            postJson(`${base}/overrides`, {
                base_rule_code: form.baseRuleCode,
                override_type: form.overrideType,
                override_config: config,
                reason: form.reason === '' ? null : form.reason,
                is_active: true,
            }),
            'Override creado.',
        );

        setSubmitting(false);

        if (result.ok) {
            setCreating(false);
        } else {
            setErrors(result.fieldErrors);
        }
    };

    // D-11: eliminar un override ahora exige confirmación (mismo patrón que
    // roles), igual que el resto de acciones destructivas.
    const remove = async (override: OverrideRow) => {
        if (base === null) {
            return;
        }

        const result = await submit(
            deleteJson(`${base}/overrides/${override.id}`),
            'Override eliminado.',
        );

        if (result.ok) {
            setDeleting(null);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center justify-between text-sm uppercase">
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
                    <div className="mb-4 flex flex-col gap-2 rounded-md border border-border p-3">
                        <div className="flex flex-wrap gap-2">
                            <div className="flex flex-col gap-1">
                                <Input
                                    placeholder="código de regla base"
                                    list="rule-codes"
                                    value={form.baseRuleCode}
                                    aria-invalid={Boolean(
                                        errors.base_rule_code,
                                    )}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            baseRuleCode: e.target.value,
                                        })
                                    }
                                    className="w-64 font-mono text-xs"
                                />
                                <InputError
                                    message={errors.base_rule_code}
                                    className="max-w-64 text-xs"
                                />
                            </div>
                            <datalist id="rule-codes">
                                {decisionRuleCodes.map((code) => (
                                    <option key={code} value={code} />
                                ))}
                            </datalist>
                            <div className="flex flex-col gap-1">
                                <select
                                    value={form.overrideType}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            overrideType: e.target.value,
                                        })
                                    }
                                    className="rounded-md border border-border bg-surface-1 px-2 py-1.5 text-xs"
                                >
                                    {overrideTypes.map((type) => (
                                        <option key={type} value={type}>
                                            {type}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={errors.override_type}
                                    className="text-xs"
                                />
                            </div>
                            <div className="flex flex-col gap-1">
                                <Input
                                    placeholder="motivo (opcional)"
                                    value={form.reason}
                                    aria-invalid={Boolean(errors.reason)}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            reason: e.target.value,
                                        })
                                    }
                                    className="w-72 text-xs"
                                />
                                <InputError
                                    message={errors.reason}
                                    className="max-w-72 text-xs"
                                />
                            </div>
                        </div>
                        <Label className="text-xs">Configuración (JSON)</Label>
                        <textarea
                            value={form.config}
                            aria-invalid={Boolean(errors.override_config)}
                            onChange={(e) =>
                                setForm({ ...form, config: e.target.value })
                            }
                            rows={4}
                            spellCheck={false}
                            className="rounded-md border border-border bg-surface-2 p-2 font-mono text-2xs text-fg-2"
                        />
                        <InputError
                            message={errors.override_config}
                            className="text-xs"
                        />
                        <div>
                            <Button
                                size="sm"
                                onClick={create}
                                disabled={submitting}
                            >
                                {submitting ? 'Creando…' : 'Crear override'}
                            </Button>
                        </div>
                    </div>
                )}

                {overrides.length === 0 ? (
                    <p className="text-xs text-fg-3">
                        Sin overrides: las reglas base aplican tal cual.
                    </p>
                ) : (
                    <table className="w-full text-left text-xs">
                        <thead className="text-2xs text-fg-3 uppercase">
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
                                    <td className="py-2 pr-4 font-mono text-2xs">
                                        {override.baseRuleCode}
                                    </td>
                                    <td className="py-2 pr-4">
                                        {override.overrideType}
                                    </td>
                                    <td className="py-2 pr-4 font-mono text-2xs">
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
                                                onClick={() =>
                                                    setDeleting(override)
                                                }
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

                <ConfirmDialog
                    open={deleting !== null}
                    title="Eliminar override"
                    description={
                        deleting
                            ? `¿Seguro que deseas eliminar el override de la regla "${deleting.baseRuleCode}"? La regla base volverá a aplicarse tal cual.`
                            : ''
                    }
                    onConfirm={() => {
                        if (deleting) {
                            return remove(deleting);
                        }
                    }}
                    onOpenChange={(open) => !open && setDeleting(null)}
                />
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
                <PageHeader
                    title="Reglas"
                    description="Motor de decisiones, mapeo de eventos del proveedor y overrides del tenant."
                />

                <div className="flex flex-wrap gap-1 border-b border-border">
                    {TABS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => setTab(item.key)}
                            className={`px-3 py-2 text-sm transition-colors ${
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
                        conditionFields={props.conditionFields}
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
