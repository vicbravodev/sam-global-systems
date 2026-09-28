import { CheckCircle2, FlaskConical, Info, XCircle } from 'lucide-react';
import { useState } from 'react';
import type { ConditionFieldDef } from '@/components/sam/condition-builder';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/format';
import { postJson } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import { describeCondition, describeValue } from './lib';
import { OutcomePill } from './rule-sentence';

interface TestCheck {
    field: string;
    operator: string;
    expected: unknown;
    actual: unknown;
    passed: boolean;
}

interface TestResponse {
    result: 'match' | 'no_match' | 'no_events';
    checks?: TestCheck[];
    event?: { evaluatedAt?: string | null; receivedAt?: string | null };
}

export interface RuleTesterProps {
    /** URL del endpoint de prueba (test-decision o test-mapping). */
    endpoint: string;
    /** Cuerpo a enviar (las condiciones actuales). */
    payload: () => Record<string, unknown>;
    fields: ConditionFieldDef[];
    /** Resultado de la regla (sólo reglas de decisión). */
    outcomeCode?: string | null;
    /** "evento evaluado" (decisión) o "alerta recibida" (traducción). */
    subject?: 'evaluation' | 'alert';
    className?: string;
}

/**
 * Probador amigable: compara las condiciones con el último evento real del
 * equipo y explica, condición por condición, qué pedía la regla y qué traía
 * el evento. Solo lectura: no guarda nada.
 */
export function RuleTester({
    endpoint,
    payload,
    fields,
    outcomeCode,
    subject = 'evaluation',
    className,
}: RuleTesterProps) {
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<TestResponse | null>(null);
    const [error, setError] = useState<string | null>(null);

    const noun =
        subject === 'alert'
            ? 'la última alerta recibida'
            : 'el último evento evaluado';

    const run = async () => {
        setTesting(true);
        setError(null);

        try {
            const response = await postJson(endpoint, payload());

            if (!response.ok) {
                setResult(null);
                setError(
                    response.status === 422
                        ? 'Completa las condiciones antes de probar.'
                        : 'No se pudo hacer la prueba.',
                );

                return;
            }

            setResult((await response.json()) as TestResponse);
        } catch {
            setResult(null);
            setError('Error de red. Vuelve a intentarlo.');
        } finally {
            setTesting(false);
        }
    };

    const when =
        result?.event?.evaluatedAt ?? result?.event?.receivedAt ?? null;

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={run}
                    disabled={testing}
                >
                    <FlaskConical className="size-3.5" />
                    {testing
                        ? 'Probando…'
                        : result
                          ? 'Probar de nuevo'
                          : `Probar con ${noun}`}
                </Button>
                {error && (
                    <span className="text-xs text-destructive">{error}</span>
                )}
            </div>

            {result?.result === 'no_events' && (
                <p className="flex items-start gap-2 rounded-md border border-border bg-surface-2 px-3 py-2 text-xs text-fg-2">
                    <Info className="mt-0.5 size-3.5 shrink-0 text-fg-3" />
                    Aún no hay eventos de tu flota para probar. Vuelve cuando
                    llegue el primero.
                </p>
            )}

            {(result?.result === 'match' || result?.result === 'no_match') && (
                <div
                    className={cn(
                        'flex flex-col gap-1 rounded-md border px-3 py-2.5',
                        result.result === 'match'
                            ? 'border-health-ok/40 bg-health-ok/10'
                            : 'border-border bg-surface-2',
                    )}
                >
                    <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium text-fg-1">
                        {result.result === 'match' ? (
                            <CheckCircle2 className="size-4 text-health-ok" />
                        ) : (
                            <XCircle className="size-4 text-fg-3" />
                        )}
                        {result.result === 'match'
                            ? `Con ${noun}, esta regla se cumpliría`
                            : `Con ${noun}, esta regla no se cumpliría`}
                        {result.result === 'match' &&
                            outcomeCode !== undefined && (
                                <OutcomePill code={outcomeCode} />
                            )}
                    </p>
                    {when && (
                        <p className="text-2xs text-fg-3">
                            Evento del {formatDateTime(when)}
                        </p>
                    )}
                </div>
            )}

            {result?.checks && result.checks.length > 0 && (
                <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                    {result.checks.map((check, index) => {
                        const expected = describeCondition(
                            check.field,
                            check.operator,
                            check.expected,
                            fields,
                        );

                        return (
                            <li
                                key={`${check.field}-${index}`}
                                className="flex items-start gap-2.5 px-3 py-2 text-xs"
                            >
                                {check.passed ? (
                                    <CheckCircle2
                                        className="mt-0.5 size-3.5 shrink-0 text-health-ok"
                                        aria-label="Se cumple"
                                    />
                                ) : (
                                    <XCircle
                                        className="mt-0.5 size-3.5 shrink-0 text-severity-critical"
                                        aria-label="No se cumple"
                                    />
                                )}
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <span className="text-fg-1">
                                        {expected.subject}
                                        {expected.predicate && (
                                            <>
                                                {' '}
                                                <span className="font-medium">
                                                    {expected.predicate}
                                                </span>
                                            </>
                                        )}
                                    </span>
                                    <span className="text-fg-3">
                                        El evento tenía:{' '}
                                        <span className="text-fg-2">
                                            {describeValue(
                                                check.field,
                                                check.actual,
                                                fields,
                                            )}
                                        </span>
                                    </span>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

/** El probador en un diálogo, para abrirlo desde el menú "…" de una regla. */
export function RuleTestDialog({
    open,
    onOpenChange,
    title,
    ...tester
}: RuleTesterProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Probar «{title}»</DialogTitle>
                    <DialogDescription>
                        Comprobamos si la regla se habría cumplido con datos
                        reales. No cambia nada.
                    </DialogDescription>
                </DialogHeader>
                {open && <RuleTester {...tester} />}
            </DialogContent>
        </Dialog>
    );
}
