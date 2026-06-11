import { CheckCircle2, FlaskConical, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { postJson } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import { OPERATOR_LABELS } from './lib';

/**
 * Probador de reglas: evalúa las condiciones del builder contra el último
 * evento/evaluación real del tenant y muestra el veredicto hoja por hoja.
 * Solo lectura — no persiste nada.
 */

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
}

interface RuleTestPanelProps {
    /** URL del endpoint de prueba (test-decision o test-mapping). */
    endpoint: string;
    /** Cuerpo a enviar (las condiciones actuales del builder). */
    payload: () => Record<string, unknown>;
    className?: string;
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '∅';
    }

    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    if (Array.isArray(value)) {
        return value.map((item) => String(item)).join(', ');
    }

    return String(value);
}

export function RuleTestPanel({
    endpoint,
    payload,
    className,
}: RuleTestPanelProps) {
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<TestResponse | null>(null);
    const [error, setError] = useState<string | null>(null);

    const run = async () => {
        setTesting(true);
        setError(null);

        try {
            const response = await postJson(endpoint, payload());

            if (!response.ok) {
                setResult(null);
                setError(
                    response.status === 422
                        ? 'Las condiciones no son válidas todavía.'
                        : 'No se pudo ejecutar la prueba.',
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

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            <div className="flex items-center gap-3">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={run}
                    disabled={testing}
                >
                    <FlaskConical className="size-3.5" />
                    {testing ? 'Probando…' : 'Probar con el último evento'}
                </Button>

                {result?.result === 'match' && (
                    <span className="flex items-center gap-1 text-xs font-medium text-health-ok">
                        <CheckCircle2 className="size-3.5" /> Coincidiría
                    </span>
                )}
                {result?.result === 'no_match' && (
                    <span className="flex items-center gap-1 text-xs font-medium text-fg-2">
                        <XCircle className="size-3.5" /> No coincidiría
                    </span>
                )}
                {result?.result === 'no_events' && (
                    <span className="text-xs text-fg-3">
                        Aún no hay eventos de este equipo para probar.
                    </span>
                )}
                {error && (
                    <span className="text-xs text-destructive">{error}</span>
                )}
            </div>

            {result?.checks && result.checks.length > 0 && (
                <table className="w-full text-left text-xs">
                    <thead className="text-[11px] text-fg-3 uppercase">
                        <tr>
                            <th className="py-1 pr-3">Campo</th>
                            <th className="py-1 pr-3">Condición</th>
                            <th className="py-1 pr-3">Esperado</th>
                            <th className="py-1 pr-3">Real</th>
                            <th className="py-1" />
                        </tr>
                    </thead>
                    <tbody>
                        {result.checks.map((check, index) => (
                            <tr
                                key={`${check.field}-${index}`}
                                className="border-t border-border/50 text-fg-2"
                            >
                                <td className="py-1.5 pr-3 font-mono text-[11px]">
                                    {check.field}
                                </td>
                                <td className="py-1.5 pr-3">
                                    {OPERATOR_LABELS[check.operator] ??
                                        check.operator}
                                </td>
                                <td className="py-1.5 pr-3 font-mono text-[11px]">
                                    {formatValue(check.expected)}
                                </td>
                                <td className="py-1.5 pr-3 font-mono text-[11px]">
                                    {formatValue(check.actual)}
                                </td>
                                <td className="py-1.5">
                                    {check.passed ? (
                                        <CheckCircle2
                                            className="size-3.5 text-health-ok"
                                            aria-label="Cumple"
                                        />
                                    ) : (
                                        <XCircle
                                            className="size-3.5 text-severity-critical"
                                            aria-label="No cumple"
                                        />
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}
