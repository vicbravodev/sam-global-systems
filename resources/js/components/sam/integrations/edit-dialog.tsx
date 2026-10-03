import { router } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { putJson, readErrorMessage } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import type { IntegrationRow } from '@/types/sam';
import { KeyHelp } from './key-help';

/** What the dialog opens for: a general edit, or straight to the key. */
export type EditMode = 'edit' | 'credentials';

export interface EditTarget {
    integration: IntegrationRow;
    mode: EditMode;
}

interface Props {
    target: EditTarget | null;
    onClose: () => void;
    teamSlug: string | null;
}

// Must match SyncDueIntegrationsJob::DEFAULT_INTERVAL_MINUTES.
const DEFAULT_CATALOG_MINUTES = 30;
const CATALOG_OPTIONS = [15, 30, 60, 180, 720];

type Json = Record<string, unknown>;

function parseConfig(text: string): Json | null {
    if (text.trim() === '') {
        return {};
    }

    try {
        const value = JSON.parse(text) as unknown;

        return value !== null && typeof value === 'object'
            ? (value as Json)
            : null;
    } catch {
        return null;
    }
}

function catalogLabel(minutes: number): string {
    return minutes < 60
        ? `Cada ${minutes} minutos`
        : minutes === 60
          ? 'Cada hora'
          : `Cada ${minutes / 60} horas`;
}

/**
 * Edit a connection: rename it, replace the access key (the common fix when
 * the provider revoked it) and tune how often SAM syncs, with switches
 * instead of raw JSON. The JSON stays editable under "Opciones avanzadas";
 * the friendly controls read and write that same JSON, so both stay in sync.
 */
export function EditDialog({ target, onClose, teamSlug }: Props) {
    const [name, setName] = useState('');
    const [credentials, setCredentials] = useState('');
    const [configText, setConfigText] = useState('');
    const [advancedOpen, setAdvancedOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [hydrated, setHydrated] = useState<EditTarget | null>(null);

    // Reset the local form whenever the dialog opens for another target.
    if (target !== null && target !== hydrated) {
        setHydrated(target);
        setName(target.integration.name);
        setCredentials('');
        setConfigText(
            target.integration.config
                ? JSON.stringify(target.integration.config, null, 2)
                : '',
        );
        setAdvancedOpen(false);
    }

    const integration = target?.integration ?? null;
    const keyFirst = target?.mode === 'credentials';

    const parsed = parseConfig(configText);
    const sync = (parsed?.sync ?? {}) as Json;
    const syncEnabled = sync.enabled !== false;
    const feedEnabled = sync.feed_enabled !== false;
    const catalogMinutes = Number(
        sync.catalog_interval_minutes ?? DEFAULT_CATALOG_MINUTES,
    );

    const setSync = (patch: Json) => {
        if (parsed === null) {
            return;
        }

        setConfigText(
            JSON.stringify({ ...parsed, sync: { ...sync, ...patch } }, null, 2),
        );
    };

    const submit = useCallback(async () => {
        if (integration === null || teamSlug === null) {
            return;
        }

        if (keyFirst && credentials.trim() === '') {
            toast.error('Pega la nueva clave de acceso.');

            return;
        }

        const config = parseConfig(configText);

        if (config === null) {
            toast.error(
                'La configuración avanzada no es válida (debe ser JSON).',
            );
            setAdvancedOpen(true);

            return;
        }

        const body: Record<string, unknown> = { name: name.trim() };

        if (credentials.trim() !== '') {
            body.credentials = credentials.trim();
        }

        if (configText.trim() !== '') {
            body.config = config;
        }

        setSubmitting(true);

        const response = await putJson(
            `/${teamSlug}/integrations/${integration.id}`,
            body,
        );

        setSubmitting(false);

        if (response.ok) {
            toast.success(
                body.credentials
                    ? 'Clave actualizada. Prueba la conexión para confirmar que funciona.'
                    : 'Conexión actualizada.',
            );
            onClose();
            router.reload({ only: ['integrations', 'summary'] });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para editar conexiones.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ??
                'No se pudo actualizar la conexión.',
        );
    }, [
        integration,
        teamSlug,
        keyFirst,
        name,
        credentials,
        configText,
        onClose,
    ]);

    const keySection = integration ? (
        <section className="flex flex-col gap-2">
            <div className="flex flex-col gap-0.5">
                <Label htmlFor="edit-credentials">
                    {keyFirst ? 'Nueva clave de acceso' : 'Clave de acceso'}
                </Label>
                <p className="text-xs text-fg-3">
                    {keyFirst
                        ? `Crea una clave nueva en ${integration.provider} y pégala aquí; reemplaza a la anterior.`
                        : 'Déjala en blanco para conservar la actual.'}
                </p>
            </div>
            {keyFirst ? (
                <KeyHelp
                    providerCode={integration.providerCode}
                    providerName={integration.provider}
                />
            ) : null}
            <Input
                id="edit-credentials"
                type="password"
                value={credentials}
                onChange={(e) => setCredentials(e.target.value)}
                placeholder={
                    keyFirst ? 'Pega aquí la nueva clave' : 'Sin cambios'
                }
                autoComplete="off"
                autoFocus={keyFirst}
            />
        </section>
    ) : null;

    return (
        <Dialog
            open={target !== null}
            onOpenChange={(next) => {
                if (!next && !submitting) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {keyFirst
                            ? 'Actualizar clave de acceso'
                            : 'Editar conexión'}
                    </DialogTitle>
                    <DialogDescription>
                        {integration
                            ? keyFirst
                                ? `${integration.name} dejó de funcionar porque ${integration.provider} ya no acepta la clave guardada.`
                                : `Cambia el nombre, la clave o cada cuánto SAM consulta a ${integration.provider}.`
                            : ''}
                    </DialogDescription>
                </DialogHeader>

                {integration ? (
                    <div className="flex flex-col gap-5 py-1">
                        {keyFirst ? keySection : null}

                        <section className="grid gap-1.5">
                            <Label htmlFor="edit-name">Nombre</Label>
                            <Input
                                id="edit-name"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                            />
                        </section>

                        {keyFirst ? null : keySection}

                        {keyFirst ? null : (
                            <>
                                <section className="flex flex-col gap-3">
                                    <div className="flex flex-col gap-0.5">
                                        <h3 className="text-sm font-semibold text-fg-1">
                                            Sincronización
                                        </h3>
                                        {parsed === null ? (
                                            <p className="text-xs text-severity-medium">
                                                La configuración avanzada tiene
                                                un error; corrígela para usar
                                                estos controles.
                                            </p>
                                        ) : null}
                                    </div>
                                    <ToggleRow
                                        id="edit-sync-enabled"
                                        label="Sincronización automática"
                                        help="SAM consulta al proveedor por su cuenta. Apágala sólo para pausar la conexión."
                                        checked={syncEnabled}
                                        disabled={parsed === null}
                                        onChange={(value) =>
                                            setSync({ enabled: value })
                                        }
                                    />
                                    <ToggleRow
                                        id="edit-feed-enabled"
                                        label="Ubicación en vivo"
                                        help="Posición, velocidad y encendido de cada unidad cada pocos segundos."
                                        checked={feedEnabled && syncEnabled}
                                        disabled={
                                            parsed === null || !syncEnabled
                                        }
                                        onChange={(value) =>
                                            setSync({ feed_enabled: value })
                                        }
                                    />
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex min-w-0 flex-col gap-0.5">
                                            <Label htmlFor="edit-catalog">
                                                Actualizar unidades y
                                                conductores
                                            </Label>
                                            <span className="text-xs text-fg-3">
                                                Para detectar altas y bajas en
                                                tu flota.
                                            </span>
                                        </div>
                                        <Select
                                            value={String(catalogMinutes)}
                                            onValueChange={(value) =>
                                                setSync({
                                                    catalog_interval_minutes:
                                                        Number(value),
                                                })
                                            }
                                            disabled={
                                                parsed === null || !syncEnabled
                                            }
                                        >
                                            <SelectTrigger
                                                id="edit-catalog"
                                                className="w-44"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {(CATALOG_OPTIONS.includes(
                                                    catalogMinutes,
                                                )
                                                    ? CATALOG_OPTIONS
                                                    : [
                                                          ...CATALOG_OPTIONS,
                                                          catalogMinutes,
                                                      ].sort((a, b) => a - b)
                                                ).map((minutes) => (
                                                    <SelectItem
                                                        key={minutes}
                                                        value={String(minutes)}
                                                    >
                                                        {catalogLabel(minutes)}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </section>

                                <Collapsible
                                    open={advancedOpen}
                                    onOpenChange={setAdvancedOpen}
                                >
                                    <CollapsibleTrigger className="flex items-center gap-1 text-xs font-medium text-fg-3 outline-none hover:text-fg-1 focus-visible:text-fg-1">
                                        <ChevronDown
                                            size={13}
                                            className={cn(
                                                'transition-transform',
                                                advancedOpen
                                                    ? 'rotate-0'
                                                    : '-rotate-90',
                                            )}
                                            aria-hidden
                                        />
                                        Opciones avanzadas (sólo si soporte te
                                        lo pide)
                                    </CollapsibleTrigger>
                                    <CollapsibleContent className="mt-3 grid gap-1.5">
                                        <Label htmlFor="edit-config">
                                            Configuración (JSON)
                                        </Label>
                                        <textarea
                                            id="edit-config"
                                            value={configText}
                                            onChange={(e) =>
                                                setConfigText(e.target.value)
                                            }
                                            rows={6}
                                            className="rounded-md border border-border bg-surface-2 px-3 py-2 font-mono text-xs"
                                        />
                                    </CollapsibleContent>
                                </Collapsible>
                            </>
                        )}
                    </div>
                ) : null}

                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={onClose}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? <Spinner className="size-3.5" /> : null}
                        {keyFirst ? 'Guardar clave' : 'Guardar'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ToggleRow({
    id,
    label,
    help,
    checked,
    disabled,
    onChange,
}: {
    id: string;
    label: string;
    help: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <div className="flex items-start justify-between gap-3">
            <div className="flex min-w-0 flex-col gap-0.5">
                <span id={`${id}-label`} className="text-sm font-medium">
                    {label}
                </span>
                <span className="text-xs text-fg-3">{help}</span>
            </div>
            <Switch
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={onChange}
                aria-labelledby={`${id}-label`}
                className="mt-0.5"
            />
        </div>
    );
}
