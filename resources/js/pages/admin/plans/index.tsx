import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

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
    limits: Record<string, number>;
}

interface AdminPlansIndexProps {
    plans: PlanRow[];
    meters: MeterOption[];
}

function PlanCard({ plan, meters }: { plan: PlanRow; meters: MeterOption[] }) {
    const [limits, setLimits] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            meters.map((m) => [m.code, String(plan.limits[m.code] ?? 0)]),
        ),
    );
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);

        const payload = Object.fromEntries(
            Object.entries(limits).map(([code, value]) => [
                code,
                Math.max(0, Number(value) || 0),
            ]),
        );

        router.put(
            `/admin/plans/${plan.id}`,
            { limits: payload },
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(`Plan ${plan.name} actualizado.`),
                onError: () => toast.error('No se pudo guardar el plan.'),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <section className="rounded-md border border-border bg-surface-1">
            <header className="flex items-center justify-between border-b border-border px-4 py-2.5">
                <div className="flex items-center gap-2">
                    <h2 className="sam-h3 m-0">{plan.name}</h2>
                    <span className="sam-meta font-mono">{plan.code}</span>
                    {plan.isActive ? null : (
                        <span className="sam-meta rounded bg-surface-2 px-1.5 py-0.5">
                            inactivo
                        </span>
                    )}
                </div>
                <span className="sam-meta tabular-nums">
                    ${plan.basePrice.toFixed(2)}/mes
                </span>
            </header>

            <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                {meters.map((meter) => (
                    <label
                        key={meter.code}
                        className="flex flex-col gap-1 text-sm"
                    >
                        <span className="sam-meta">{meter.name}</span>
                        <Input
                            type="number"
                            min={0}
                            value={limits[meter.code] ?? '0'}
                            onChange={(e) =>
                                setLimits((prev) => ({
                                    ...prev,
                                    [meter.code]: e.target.value,
                                }))
                            }
                        />
                    </label>
                ))}
            </div>

            <footer className="flex justify-end border-t border-border px-4 py-2.5">
                <Button size="sm" onClick={save} disabled={saving}>
                    Guardar
                </Button>
            </footer>
        </section>
    );
}

export default function AdminPlansIndex({
    plans,
    meters,
}: AdminPlansIndexProps) {
    return (
        <div className="flex h-full flex-col overflow-hidden">
            <Head title="Planes" />

            <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
                <div className="flex items-center gap-3">
                    <h1 className="sam-h2 m-0">Planes</h1>
                    <span className="sam-meta">
                        {plans.length} planes · topes por medidor
                    </span>
                </div>
            </header>

            <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-5">
                {plans.map((plan) => (
                    <PlanCard key={plan.id} plan={plan} meters={meters} />
                ))}
            </div>
        </div>
    );
}
