import { Head, router } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { BillingPill } from '@/components/sam/billing/panel';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { ListPage } from '@/components/sam/list-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { meterLabel } from '@/lib/labels';
import { cn } from '@/lib/utils';
import {
    index as plansIndex,
    update as updatePlan,
} from '@/routes/admin/plans';

interface MeterOption {
    code: string;
    name: string;
}

interface PlanRow {
    id: number;
    code: string;
    name: string;
    basePrice: number;
    isActive: boolean;
    tenantsCount: number;
    limits: Record<string, number>;
}

interface AdminPlansIndexProps {
    plans: PlanRow[];
    meters: MeterOption[];
}

function initialLimits(
    plan: PlanRow,
    meters: MeterOption[],
): Record<string, string> {
    return Object.fromEntries(
        meters.map((m) => [m.code, String(plan.limits[m.code] ?? 0)]),
    );
}

function PlanCard({ plan, meters }: { plan: PlanRow; meters: MeterOption[] }) {
    const [limits, setLimits] = useState(() => initialLimits(plan, meters));
    const [baseline, setBaseline] = useState(limits);
    const [confirming, setConfirming] = useState(false);
    const [saving, setSaving] = useState(false);

    const changed = useMemo(
        () => meters.filter((m) => limits[m.code] !== baseline[m.code]),
        [meters, limits, baseline],
    );
    const dirty = changed.length > 0;

    const save = () =>
        new Promise<void>((resolve) => {
            const payload = Object.fromEntries(
                Object.entries(limits).map(([code, value]) => [
                    code,
                    Math.max(0, Math.floor(Number(value) || 0)),
                ]),
            );

            router.put(
                updatePlan(plan.id).url,
                { limits: payload },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => setSaving(true),
                    onSuccess: () => setBaseline(limits),
                    onError: (errors) =>
                        toast.error(
                            Object.values(errors)[0] ??
                                'No se pudo guardar el plan.',
                        ),
                    onFinish: () => {
                        setSaving(false);
                        resolve();
                    },
                },
            );
        });

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (dirty) {
            setConfirming(true);
        }
    };

    return (
        <section className="rounded-lg border border-border bg-surface-1">
            <header className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="sam-h3">{plan.name}</h2>
                    <span className="font-mono text-3xs text-fg-3">
                        {plan.code}
                    </span>
                    {plan.isActive ? null : (
                        <BillingPill tone="neutral">Inactivo</BillingPill>
                    )}
                </div>
                <span className="text-xs text-fg-3">
                    {plan.tenantsCount === 0
                        ? 'Ningún cliente en este plan'
                        : `${plan.tenantsCount} ${plan.tenantsCount === 1 ? 'cliente' : 'clientes'} en este plan`}
                </span>
            </header>

            <form onSubmit={submit}>
                <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    {meters.map((meter) => {
                        const id = `plan-${plan.id}-${meter.code}`;
                        const edited =
                            limits[meter.code] !== baseline[meter.code];

                        return (
                            <div key={meter.code} className="grid gap-1.5">
                                <Label htmlFor={id}>
                                    {meterLabel(meter.code, meter.name)}
                                </Label>
                                <Input
                                    id={id}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    value={limits[meter.code] ?? '0'}
                                    className={cn(
                                        'tabular-nums',
                                        edited && 'border-primary',
                                    )}
                                    onChange={(e) =>
                                        setLimits((prev) => ({
                                            ...prev,
                                            [meter.code]: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                        );
                    })}
                </div>

                <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-border px-4 py-3">
                    {dirty ? (
                        <>
                            <span className="mr-auto text-xs text-fg-3">
                                {changed.length}{' '}
                                {changed.length === 1
                                    ? 'cambio sin guardar'
                                    : 'cambios sin guardar'}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setLimits(baseline)}
                                disabled={saving}
                            >
                                Descartar
                            </Button>
                        </>
                    ) : null}
                    <Button type="submit" size="sm" disabled={!dirty || saving}>
                        {saving && <Spinner />}
                        Guardar topes
                    </Button>
                </footer>
            </form>

            <ConfirmDialog
                open={confirming}
                title={`Guardar los topes de ${plan.name}`}
                description={
                    plan.tenantsCount > 0
                        ? `El cambio aplica de inmediato a ${plan.tenantsCount} ${plan.tenantsCount === 1 ? 'cliente' : 'clientes'} con este plan (salvo los que tengan topes manuales).`
                        : 'Ningún cliente usa este plan todavía; aplicará a los que lo reciban.'
                }
                confirmLabel="Guardar topes"
                tone="default"
                onOpenChange={setConfirming}
                onConfirm={async () => {
                    await save();
                    setConfirming(false);
                }}
            />
        </section>
    );
}

export default function AdminPlansIndex({
    plans,
    meters,
}: AdminPlansIndexProps) {
    return (
        <>
            <Head title="Planes" />
            <ListPage
                title="Planes"
                description="Plantillas de topes incluidos por medidor. El cobro es por tracto-día con los términos de cada cliente."
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {plans.length}
                        </span>{' '}
                        {plans.length === 1 ? 'plan' : 'planes'}
                    </span>
                }
            >
                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    {plans.length === 0 ? (
                        <EmptyState
                            icon={Layers}
                            title="Sin planes"
                            description="Los planes se siembran con php artisan db:seed (PlanSeeder)."
                        />
                    ) : (
                        <div className="grid max-w-5xl gap-4">
                            {plans.map((plan) => (
                                <PlanCard
                                    key={plan.id}
                                    plan={plan}
                                    meters={meters}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </ListPage>
        </>
    );
}

AdminPlansIndex.layout = {
    breadcrumbs: [{ title: 'Planes', href: plansIndex().url }],
};
