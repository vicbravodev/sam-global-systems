import { Check, Copy } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

interface Props {
    value: string;
    /** What is being copied, for the toast and the button label. */
    label: string;
}

/** Read-only monospace value with a copy button (addresses, identifiers). */
export function CopyField({ value, label }: Props) {
    const [copied, setCopied] = useState(false);

    const copy = useCallback(async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            toast.success(`${label} copiada.`);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            toast.error('No se pudo copiar.');
        }
    }, [value, label]);

    return (
        <div className="flex min-w-0 items-center gap-1.5 rounded-md border border-border bg-surface-2 py-1 pr-1 pl-2.5">
            <code className="min-w-0 flex-1 truncate font-mono text-2xs text-fg-2">
                {value}
            </code>
            <Button
                size="sm"
                variant="ghost"
                className="h-7 shrink-0 gap-1 px-2 text-2xs"
                onClick={copy}
                aria-label={`Copiar ${label.toLowerCase()}`}
            >
                {copied ? (
                    <Check size={12} className="text-severity-low" />
                ) : (
                    <Copy size={12} />
                )}
                {copied ? 'Copiada' : 'Copiar'}
            </Button>
        </div>
    );
}
