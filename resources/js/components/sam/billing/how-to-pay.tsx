import { Check, Copy, Mail } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Panel } from '@/components/sam/panel';
import { Button } from '@/components/ui/button';
import type { TransferDetails } from './types';

function CopyableClabe({ clabe }: { clabe: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(clabe);
            setCopied(true);
            toast.success('CLABE copiada');
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error('No se pudo copiar. Selecciónala a mano.');
        }
    };

    return (
        <button
            type="button"
            onClick={() => void copy()}
            className="flex items-center gap-1.5 font-mono text-xs text-fg-1 hover:text-primary"
            aria-label="Copiar CLABE"
        >
            <span className="break-all">{clabe}</span>
            {copied ? (
                <Check className="size-3.5 shrink-0 text-severity-low" />
            ) : (
                <Copy className="size-3.5 shrink-0 text-fg-3" />
            )}
        </button>
    );
}

/**
 * El pago es por transferencia bancaria: SAM emite la factura, el cliente
 * transfiere y sube el comprobante, SAM confirma. Sin checkout.
 */
export function HowToPay({
    transfer,
    mailtoHref,
}: {
    transfer: TransferDetails | null;
    mailtoHref: string | null;
}) {
    const steps = [
        'Al cierre de cada mes aparece tu factura en esta página.',
        'Transfiere el total a la cuenta de SAM.',
        'Sube el comprobante en la factura.',
        'SAM confirma el pago y la factura queda como pagada.',
    ];

    return (
        <Panel
            title="Cómo pagar"
            description="Pago por transferencia bancaria, sin tarjeta."
            bodyClassName="gap-4 px-4 py-4"
        >
            <ol className="flex flex-col gap-2.5">
                {steps.map((step, index) => (
                    <li key={step} className="flex items-start gap-2.5 text-xs">
                        <span className="grid size-5 shrink-0 place-items-center rounded-full bg-surface-3 text-2xs font-semibold text-fg-2">
                            {index + 1}
                        </span>
                        <span className="pt-0.5 text-fg-2">{step}</span>
                    </li>
                ))}
            </ol>

            {transfer ? (
                <dl className="flex flex-col divide-y divide-border rounded-md border border-border text-xs">
                    {transfer.beneficiary && (
                        <div className="flex flex-col gap-0.5 px-3 py-2">
                            <dt className="text-2xs text-fg-3">Beneficiario</dt>
                            <dd className="text-fg-1">
                                {transfer.beneficiary}
                            </dd>
                        </div>
                    )}
                    {transfer.bank && (
                        <div className="flex flex-col gap-0.5 px-3 py-2">
                            <dt className="text-2xs text-fg-3">Banco</dt>
                            <dd className="text-fg-1">{transfer.bank}</dd>
                        </div>
                    )}
                    <div className="flex flex-col gap-0.5 px-3 py-2">
                        <dt className="text-2xs text-fg-3">CLABE</dt>
                        <dd>
                            <CopyableClabe clabe={transfer.clabe} />
                        </dd>
                    </div>
                </dl>
            ) : (
                <p className="rounded-md border border-border bg-surface-2 px-3 py-2 text-xs text-fg-2">
                    Te enviamos los datos bancarios junto con tu factura. Si no
                    los tienes a la mano, escríbenos.
                </p>
            )}

            {mailtoHref && (
                <div className="flex flex-col gap-1.5">
                    <p className="text-2xs text-fg-3">
                        ¿Algo no cuadra con un monto o una factura?
                    </p>
                    <Button
                        size="sm"
                        variant="outline"
                        asChild
                        className="self-start"
                    >
                        <a href={mailtoHref}>
                            <Mail size={13} />
                            Escribir a facturación
                        </a>
                    </Button>
                </div>
            )}
        </Panel>
    );
}
