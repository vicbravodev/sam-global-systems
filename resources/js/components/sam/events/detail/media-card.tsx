import { Film, Image as ImageIcon, Mic, Play } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { MEDIA_TYPE_LABELS } from '@/components/sam/events/copy';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { mediaRoleLabel } from '@/lib/labels';
import type { EventMediaItem } from '@/types/events';

const MEDIA_ICONS: Record<string, LucideIcon> = {
    image: ImageIcon,
    snapshot: ImageIcon,
    video: Film,
    clip: Film,
    audio: Mic,
};

function isVideo(item: EventMediaItem): boolean {
    return (
        item.mediaType === 'video' ||
        item.mediaType === 'clip' ||
        (item.mimeType ?? '').startsWith('video/')
    );
}

export function MediaCard({ media }: { media: EventMediaItem[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Film size={15} /> Evidencia
                </CardTitle>
                <span className="sam-meta">
                    {media.length} {media.length === 1 ? 'archivo' : 'archivos'}
                </span>
            </CardHeader>
            <CardContent className="p-4">
                {media.length === 0 ? (
                    <p className="text-sm text-fg-3">
                        Sin media asociada. Para eventos con cámara, SAM pide el
                        clip y las capturas alrededor del momento del evento.
                    </p>
                ) : (
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {media.map((item) => (
                            <MediaTile key={item.id} item={item} />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function MediaTile({ item }: { item: EventMediaItem }) {
    const video = isVideo(item);
    // Una foto es su propia miniatura; un clip usa su primer frame extraído.
    const preview = item.thumbnailUrl ?? (video ? null : item.url);
    // Las URLs firmadas expiran (30 min): degradar al ícono en vez de dejar
    // una imagen rota en pestañas long-lived.
    const [previewFailed, setPreviewFailed] = useState(false);
    // Un clip sin frame extraído todavía: el propio video da el primer cuadro.
    const videoPreview =
        video && preview === null && item.url !== null && !previewFailed;
    const Icon = (item.mediaType && MEDIA_ICONS[item.mediaType]) || Film;
    const label =
        (item.mediaType && MEDIA_TYPE_LABELS[item.mediaType]) || 'Media';

    return (
        <a
            href={item.url ?? '#'}
            target="_blank"
            rel="noreferrer"
            className="group flex flex-col overflow-hidden rounded-md border border-border bg-surface-2 transition-colors hover:border-primary/40"
        >
            <span className="relative grid aspect-video place-items-center overflow-hidden bg-surface-3 text-fg-3">
                {preview && !previewFailed ? (
                    <img
                        src={preview}
                        alt={`${label} #${item.id}`}
                        loading="lazy"
                        decoding="async"
                        onError={() => setPreviewFailed(true)}
                        className="h-full w-full object-cover transition-transform group-hover:scale-[1.02]"
                    />
                ) : videoPreview ? (
                    <video
                        src={`${item.url}#t=0.5`}
                        preload="metadata"
                        muted
                        playsInline
                        aria-hidden
                        onError={() => setPreviewFailed(true)}
                        className="pointer-events-none h-full w-full object-cover"
                    />
                ) : (
                    <Icon size={22} strokeWidth={1.5} />
                )}
                {video &&
                    (preview !== null || videoPreview) &&
                    !previewFailed && (
                        <span className="absolute inset-0 grid place-items-center">
                            <span className="grid size-8 place-items-center rounded-full bg-black/60 text-white">
                                <Play
                                    size={15}
                                    strokeWidth={2}
                                    className="ml-0.5"
                                />
                            </span>
                        </span>
                    )}
                {item.durationSeconds !== null && (
                    <span className="absolute right-1.5 bottom-1.5 rounded-sm bg-black/70 px-1.5 py-0.5 font-mono text-3xs text-white tabular-nums">
                        {Math.floor(item.durationSeconds / 60)}:
                        {String(item.durationSeconds % 60).padStart(2, '0')}
                    </span>
                )}
            </span>
            <span className="flex items-center justify-between gap-2 px-2 py-1.5">
                <span className="text-2xs text-fg-2">
                    {label}
                    {item.mediaRole && ` · ${mediaRoleLabel(item.mediaRole)}`}
                </span>
                {item.capturedAt && (
                    <span className="font-mono text-3xs text-fg-3">
                        {formatDateTime(item.capturedAt)}
                    </span>
                )}
            </span>
        </a>
    );
}
