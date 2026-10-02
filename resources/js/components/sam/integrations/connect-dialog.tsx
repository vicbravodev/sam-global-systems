import { router } from '@inertiajs/react';
import { Check, ChevronDown, Loader2 } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { RadioCard, RadioCardGroup } from '@/components/sam/radio-card-group';
import { Step } from '@/components/sam/step';
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
import { postJson, readErrorMessage } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import type { AuthTypeOption, IntegrationProviderOption } from '@/types/sam';
import { capabilityLabel } from './integration-state';
import { KeyHelp } from './key-help';
import { ProviderTile } from './provider-tile';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    providers: IntegrationProviderOption[];
    authTypes: AuthTypeOption[];
    teamSlug: string | null;
}

/**
 * Guided "connect a provider" flow in three numbered steps: pick the
 * provider, paste the access key (with where-to-find-it help) and name the
 * connection. The auth method and raw JSON config stay available under
 * "Opciones avanzadas" for support, out of the operator's way.
 */
export function ConnectDialog({
    open,
    onOpenChange,
    providers,
    authTypes,
    teamSlug,
}: Props) {
    const onlyProvider = providers.length === 1 ? providers[0] : null;
    const defaultAuth = authTypes[0]?.value ?? '';

    const [providerId, setProviderId] = useState(
        onlyProvider ? String(onlyProvider.id) : '',
    );
    const [name, setName] = useState(onlyProvider?.name ?? '');
    const [nameTouched, setNameTouched] = useState(false);
    const [authType, setAuthType] = useState(defaultAuth);
    const [credentials, setCredentials] = useState('');
    const [config, setConfig] = useState('');
    const [advancedOpen, setAdvancedOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const provider = providers.find((p) => String(p.id) === providerId) ?? null;

    const reset = useCallback(() => {
        setProviderId(onlyProvider ? String(onlyProvider.id) : '');
        setName(onlyProvider?.name ?? '');
        setNameTouched(false);
        setAuthType(defaultAuth);
        setCredentials('');
        setConfig('');
        setAdvancedOpen(false);
    }, [onlyProvider, defaultAuth]);

    const handleOpenChange = (next: boolean) => {
        if (!next) {
            reset();
        }

        onOpenChange(next);
    };

    const pickProvider = (option: IntegrationProviderOption) => {
        setProviderId(String(option.id));

        if (!nameTouched) {
            setName(option.name);
        }
    };

    const submit = useCallback(async () => {
        if (teamSlug === null) {
            toast.error('No hay equipo activo.');

            return;
        }

        if (providerId === '') {
            toast.error('Elige el proveedor que usas.');

            return;
        }

        if (credentials.trim() === '') {
            toast.error('Pega la clave de acceso del proveedor.');

            return;
        }

        if (name.trim() === '') {
            toast.error('Ponle un nombre a la conexión.');

            return;
        }

        let parsedConfig: Record<string, unknown> | undefined;

        if (config.trim() !== '') {
            try {
                parsedConfig = JSON.parse(config) as Record<string, unknown>;
            } catch {
                toast.error(
                    'La configuración avanzada no es válida (debe ser JSON).',
                );
                setAdvancedOpen(true);

                return;
            }
        }

        setSubmitting(true);

        const response = await postJson(`/${teamSlug}/integrations`, {
            provider_id: Number(providerId),
            name: name.trim(),
            auth_type: authType,
            credentials: credentials.trim(),
            config: parsedConfig,
        });

        setSubmitting(false);

        if (response.ok) {
            toast.success(
                'Conexión creada. SAM está trayendo tus unidades y conductores.',
            );
            reset();
            onOpenChange(false);
            router.reload({ only: ['integrations', 'summary'] });

            return;
        }

        if (response.status === 403) {
            toast.error('No tienes permisos para conectar proveedores.');

            return;
        }

        toast.error(
            (await readErrorMessage(response)) ??
                'No se pudo crear la conexión.',
        );
    }, [
        teamSlug,
        providerId,
        name,
        authType,
        credentials,
        config,
        reset,
        onOpenChange,
    ]);

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Conectar un proveedor</DialogTitle>
                    <DialogDescription>
                        SAM se conecta a tu proveedor de rastreo para recibir
                        ubicaciones, eventos de seguridad y alertas de tu flota.
                        Toma un par de minutos.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-5 py-1">
                    <Step
                        step={1}
                        title="¿Qué proveedor usas?"
                        done={provider !== null}
                    >
                        {providers.length === 0 ? (
                            <p className="text-xs text-fg-3">
                                No hay proveedores disponibles por ahora.
                                Contacta a soporte.
                            </p>
                        ) : (
                            <RadioCardGroup
                                label="Proveedor"
                                className="grid gap-2 sm:grid-cols-2"
                            >
                                {providers.map((option) => {
                                    const selected =
                                        String(option.id) === providerId;

                                    return (
                                        <RadioCard
                                            key={option.id}
                                            selected={selected}
                                            onSelect={() =>
                                                pickProvider(option)
                                            }
                                            className="gap-2.5 p-2.5"
                                            leading={
                                                <ProviderTile
                                                    name={option.name}
                                                    size="sm"
                                                />
                                            }
                                            label={
                                                <>
                                                    {option.name}
                                                    {selected ? (
                                                        <Check
                                                            size={13}
                                                            className="text-primary"
                                                            aria-hidden
                                                        />
                                                    ) : null}
                                                </>
                                            }
                                            description={
                                                option.capabilities.length >
                                                0 ? (
                                                    <span className="line-clamp-2 text-2xs">
                                                        {option.capabilities
                                                            .map(
                                                                capabilityLabel,
                                                            )
                                                            .join(' · ')}
                                                    </span>
                                                ) : null
                                            }
                                        />
                                    );
                                })}
                            </RadioCardGroup>
                        )}
                    </Step>

                    <Step
                        step={2}
                        title="Pega la clave de acceso"
                        help="Es la llave que permite a SAM leer los datos de tu flota. Se guarda cifrada y nadie puede volver a verla."
                        done={credentials.trim() !== ''}
                    >
                        <KeyHelp
                            providerCode={provider?.code ?? null}
                            providerName={provider?.name ?? null}
                        />
                        <Label
                            htmlFor="connect-credentials"
                            className="sr-only"
                        >
                            Clave de acceso
                        </Label>
                        <Input
                            id="connect-credentials"
                            type="password"
                            value={credentials}
                            onChange={(e) => setCredentials(e.target.value)}
                            placeholder="Pega aquí la clave"
                            autoComplete="off"
                        />
                    </Step>

                    <Step
                        step={3}
                        title="Ponle un nombre"
                        help="Para reconocerla en SAM, sobre todo si conectas más de una cuenta (p. ej. «Samsara — flota norte»)."
                        done={name.trim() !== ''}
                    >
                        <Label htmlFor="connect-name" className="sr-only">
                            Nombre de la conexión
                        </Label>
                        <Input
                            id="connect-name"
                            value={name}
                            onChange={(e) => {
                                setName(e.target.value);
                                setNameTouched(true);
                            }}
                            placeholder="Mi cuenta de Samsara"
                        />
                    </Step>

                    <p className="rounded-md bg-surface-2 px-3 py-2 text-xs leading-relaxed text-fg-2">
                        Al conectar, SAM trae tus unidades y conductores
                        automáticamente. Las unidades llegan sin monitorear: tú
                        eliges cuáles vigilar desde{' '}
                        <strong className="text-fg-1">Flota</strong>.
                    </p>

                    <Collapsible
                        open={advancedOpen}
                        onOpenChange={setAdvancedOpen}
                    >
                        <CollapsibleTrigger className="flex items-center gap-1 text-xs font-medium text-fg-3 outline-none hover:text-fg-1 focus-visible:text-fg-1">
                            <ChevronDown
                                size={13}
                                className={cn(
                                    'transition-transform',
                                    advancedOpen ? 'rotate-0' : '-rotate-90',
                                )}
                                aria-hidden
                            />
                            Opciones avanzadas (sólo si soporte te lo pide)
                        </CollapsibleTrigger>
                        <CollapsibleContent className="mt-3 flex flex-col gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="connect-auth">
                                    Método de acceso
                                </Label>
                                <Select
                                    value={authType}
                                    onValueChange={setAuthType}
                                >
                                    <SelectTrigger id="connect-auth">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {authTypes.map((type) => (
                                            <SelectItem
                                                key={type.value}
                                                value={type.value}
                                            >
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="connect-config">
                                    Configuración (JSON)
                                </Label>
                                <textarea
                                    id="connect-config"
                                    value={config}
                                    onChange={(e) => setConfig(e.target.value)}
                                    placeholder='{"sync": {"catalog_interval_minutes": 30}}'
                                    rows={3}
                                    className="rounded-md border border-border bg-surface-2 px-3 py-2 font-mono text-xs"
                                />
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => handleOpenChange(false)}
                        disabled={submitting}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={submit} disabled={submitting}>
                        {submitting ? (
                            <Loader2 size={14} className="animate-spin" />
                        ) : null}
                        Conectar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
