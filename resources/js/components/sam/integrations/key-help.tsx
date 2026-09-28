import { ExternalLink, KeyRound } from 'lucide-react';
import { cn } from '@/lib/utils';

interface Props {
    /** Provider code (`samsara`) to pick provider-specific instructions. */
    providerCode: string | null;
    providerName: string | null;
    className?: string;
}

/**
 * "Where do I get the key?" — the step an operator gets stuck on. Samsara
 * gets exact dashboard steps (developers.samsara.com/docs/authentication);
 * any other provider gets the generic version.
 */
export function KeyHelp({ providerCode, providerName, className }: Props) {
    const isSamsara = providerCode === 'samsara';
    const name = providerName ?? 'tu proveedor';

    return (
        <div
            className={cn(
                'flex flex-col gap-2 rounded-md border border-border bg-surface-2 p-3 text-xs text-fg-2',
                className,
            )}
        >
            <p className="flex items-center gap-1.5 font-semibold text-fg-1">
                <KeyRound size={13} className="text-fg-3" aria-hidden />
                ¿Dónde consigo la clave?
            </p>
            {isSamsara ? (
                <ol className="flex list-decimal flex-col gap-1 pl-4 leading-relaxed marker:text-fg-3">
                    <li>
                        Entra a{' '}
                        <a
                            href="https://cloud.samsara.com"
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-0.5 font-medium text-primary underline-offset-2 hover:underline"
                        >
                            cloud.samsara.com
                            <ExternalLink size={11} aria-hidden />
                        </a>{' '}
                        con un usuario administrador.
                    </li>
                    <li>
                        Abre <strong className="text-fg-1">Ajustes</strong> (el
                        engrane del menú izquierdo) y baja hasta{' '}
                        <strong className="text-fg-1">API Tokens</strong>.
                    </li>
                    <li>
                        Pulsa{' '}
                        <strong className="text-fg-1">Add an API Token</strong>,
                        llámalo «SAM» y deja los permisos de lectura que vienen
                        marcados. Añade también la lectura de{' '}
                        <strong className="text-fg-1">
                            Safety Events &amp; Scores
                        </strong>
                        .
                    </li>
                    <li>
                        Copia la clave en ese momento (Samsara no vuelve a
                        mostrarla) y pégala aquí.
                    </li>
                </ol>
            ) : (
                <p className="leading-relaxed">
                    En el portal de {name}, busca la sección de claves o tokens
                    de API, crea una nueva para SAM con permisos de lectura y
                    pégala aquí.
                </p>
            )}
        </div>
    );
}
