import { Trash2 } from 'lucide-react';
import { NOTIFICATION_PROVIDER_LABELS } from '@/components/sam/admin/copy';
import { MetaChip } from '@/components/sam/meta-chip';
import { StatusBadge } from '@/components/sam/status-badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    destroy as destroyChannel,
    update as updateChannel,
} from '@/routes/admin/channels';
import { visit } from './lib';
import type { PendingChannelAction, PlatformChannel } from './types';

/**
 * One platform channel: type, provider, configured keys, on/off switch and
 * delete. Both actions go through the confirmation dialog (`onConfirm`).
 */
export function ChannelRow({
    channel,
    typeLabel,
    onConfirm: setPending,
}: {
    channel: PlatformChannel;
    typeLabel: string;
    onConfirm: (action: PendingChannelAction) => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
            <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                    {channel.name}
                    <span className="font-mono text-3xs font-normal text-fg-3">
                        {channel.code}
                    </span>
                </p>
                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    <MetaChip>{typeLabel}</MetaChip>
                    <MetaChip>
                        {NOTIFICATION_PROVIDER_LABELS[channel.provider] ??
                            channel.provider}
                    </MetaChip>
                    {channel.configKeys.length > 0 ? (
                        <span className="text-xs text-fg-3">
                            Configura: {channel.configKeys.join(', ')}
                        </span>
                    ) : (
                        <span className="text-xs text-fg-3">
                            Usa los defaults de plataforma
                        </span>
                    )}
                </div>
            </div>
            <StatusBadge
                size="sm"
                tone={channel.isActive ? 'ok' : 'neutral'}
                label={channel.isActive ? 'Activo' : 'Apagado'}
            />
            <Switch
                checked={channel.isActive}
                aria-label={`${channel.isActive ? 'Apagar' : 'Encender'} ${channel.name}`}
                onCheckedChange={(next) =>
                    setPending({
                        title: `${next ? 'Encender' : 'Apagar'} ${channel.name}`,
                        description: next
                            ? 'Todos los clientes vuelven a recibir avisos por este canal (salvo los que lo apagaron).'
                            : 'Ningún cliente recibirá avisos por este canal, incluidos los de pánico, hasta que lo enciendas.',
                        confirmLabel: next ? 'Encender' : 'Apagar',
                        tone: next ? 'default' : 'destructive',
                        run: () =>
                            visit('put', updateChannel(channel.id).url, {
                                is_active: next,
                            }),
                    })
                }
            />
            <Button
                size="icon"
                variant="ghost"
                className="size-8 text-fg-3 hover:text-destructive"
                aria-label={`Eliminar canal ${channel.name}`}
                onClick={() =>
                    setPending({
                        title: `Eliminar ${channel.name}`,
                        description:
                            'Se elimina para todos los clientes. No se puede deshacer.',
                        confirmLabel: 'Eliminar canal',
                        tone: 'destructive',
                        run: () =>
                            visit('delete', destroyChannel(channel.id).url),
                    })
                }
            >
                <Trash2 className="size-3.5" />
            </Button>
        </li>
    );
}
