import { Head } from '@inertiajs/react';
import { Plus, Radio } from 'lucide-react';
import { useState } from 'react';
import { ChannelRow } from '@/components/sam/admin/channels/channel-row';
import { CreateChannelSheet } from '@/components/sam/admin/channels/create-channel-sheet';
import type {
    ChannelTypeOption,
    PendingChannelAction,
    PlatformChannel,
} from '@/components/sam/admin/channels/types';
import { ConfirmDialog } from '@/components/sam/confirm-dialog';
import { ListPage } from '@/components/sam/list-page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { index as channelsIndex } from '@/routes/admin/channels';

interface AdminChannelsIndexProps {
    channels: PlatformChannel[];
    channelTypes: ChannelTypeOption[];
}

export default function AdminChannelsIndex({
    channels,
    channelTypes,
}: AdminChannelsIndexProps) {
    const [createOpen, setCreateOpen] = useState(false);
    const [pending, setPending] = useState<PendingChannelAction | null>(null);
    const typeLabel = (value: string | null) =>
        channelTypes.find((t) => t.value === value)?.label ??
        value ??
        'Sin tipo';

    return (
        <>
            <Head title="Canales de plataforma" />
            <ListPage
                title="Canales"
                description="Por dónde SAM avisa a los clientes. Apagar un canal corta ese aviso para todos."
                meta={
                    <span className="text-xs text-fg-3">
                        <span className="font-medium text-fg-1">
                            {channels.filter((c) => c.isActive).length}
                        </span>{' '}
                        de {channels.length} activos
                    </span>
                }
                actions={
                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                        <Plus className="size-3.5" />
                        Nuevo canal
                    </Button>
                }
            >
                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                    <section className="max-w-5xl rounded-lg border border-border bg-surface-1">
                        {channels.length === 0 ? (
                            <EmptyState
                                icon={Radio}
                                title="Sin canales de plataforma"
                                description="Siémbralos con db:seed (PlatformChannelSeeder) o crea el primero."
                                action={
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="size-3.5" />
                                        Nuevo canal
                                    </Button>
                                }
                            />
                        ) : (
                            <ul className="divide-y divide-border">
                                {channels.map((channel) => (
                                    <ChannelRow
                                        key={channel.id}
                                        channel={channel}
                                        typeLabel={typeLabel(
                                            channel.channelType,
                                        )}
                                        onConfirm={setPending}
                                    />
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </ListPage>

            <CreateChannelSheet
                open={createOpen}
                onOpenChange={setCreateOpen}
                channelTypes={channelTypes}
            />

            <ConfirmDialog
                open={pending !== null}
                title={pending?.title ?? ''}
                description={pending?.description ?? ''}
                confirmLabel={pending?.confirmLabel}
                tone={pending?.tone}
                onOpenChange={(open) => !open && setPending(null)}
                onConfirm={async () => {
                    await pending?.run();
                    setPending(null);
                }}
            />
        </>
    );
}

AdminChannelsIndex.layout = {
    breadcrumbs: [{ title: 'Canales', href: channelsIndex().url }],
};
