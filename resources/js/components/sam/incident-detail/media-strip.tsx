import { usePage } from '@inertiajs/react';
import {
    Camera,
    Check,
    ChevronLeft,
    ChevronRight,
    Clapperboard,
    FileQuestion,
    HelpCircle,
    Loader2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { postJson, readErrorMessage } from '@/lib/sam-fetch';
import { cn } from '@/lib/utils';
import type {
    IncidentMediaAssessment,
    IncidentMediaItem,
    IncidentMediaRequestSummary,
} from '@/types/sam';
import { mediaResultLabel } from './media-verdict';

const PENDING_REQUEST_STATUSES = ['pending', 'sent', 'processing'];

function isVideo(item: IncidentMediaItem): boolean {
    return (
        item.mediaType === 'video' ||
        item.mediaType === 'clip' ||
        (item.mimeType ?? '').startsWith('video/')
    );
}

function VerdictBadge({ result }: { result: string | null }) {
    if (result === null) {
        return null;
    }

    const tone =
        result === 'contradicts_event'
            ? 'bg-severity-high text-white'
            : result === 'confirms_event'
              ? 'bg-health-ok text-white'
              : 'bg-black/70 text-white/90';
    const Icon =
        result === 'contradicts_event'
            ? X
            : result === 'confirms_event'
              ? Check
              : HelpCircle;

    return (
        <span
            className={cn(
                'absolute bottom-1 left-1 inline-grid size-4.5 place-items-center rounded-full',
                tone,
            )}
            title={mediaResultLabel(result)}
        >
            <Icon size={11} strokeWidth={2.25} />
        </span>
    );
}

function MediaThumb({
    item,
    result,
    onOpen,
}: {
    item: IncidentMediaItem;
    result: string | null;
    onOpen: () => void;
}) {
    const video = isVideo(item);
    const preview = item.thumbnailUrl ?? (video ? null : item.url);
    // Las URLs firmadas expiran (30 min): degradar al ícono en vez de dejar
    // una pared de imágenes rotas en pestañas long-lived.
    const [previewFailed, setPreviewFailed] = useState(false);

    return (
        <button
            type="button"
            onClick={onOpen}
            disabled={item.url === null}
            className="group relative flex h-24 w-40 shrink-0 items-center justify-center overflow-hidden rounded-md border border-border bg-surface-2 transition-colors hover:border-fg-3 disabled:cursor-not-allowed disabled:opacity-60"
            aria-label={`Abrir media #${item.id}`}
        >
            {preview && !previewFailed ? (
                <img
                    src={preview}
                    alt={`Media del incidente #${item.id}`}
                    className="h-full w-full object-cover"
                    loading="lazy"
                    onError={() => setPreviewFailed(true)}
                />
            ) : (
                <span className="flex flex-col items-center gap-1 text-fg-3">
                    {video ? (
                        <Clapperboard size={18} strokeWidth={1.5} />
                    ) : (
                        <Camera size={18} strokeWidth={1.5} />
                    )}
                    <span className="text-3xs uppercase">
                        {item.mediaType ?? 'media'}
                    </span>
                </span>
            )}
            <VerdictBadge result={result} />
            {video && item.durationSeconds !== null && (
                <span className="absolute right-1 bottom-1 rounded bg-black/70 px-1 font-mono text-3xs text-white">
                    {Math.floor(item.durationSeconds / 60)}:
                    {String(item.durationSeconds % 60).padStart(2, '0')}
                </span>
            )}
        </button>
    );
}

interface MediaStripProps {
    incidentId: number;
    media: IncidentMediaItem[];
    assessments: IncidentMediaAssessment[];
    requests: IncidentMediaRequestSummary[];
    onMutated: () => void;
}

/**
 * Tira horizontal de media del evento: altura fija independiente de la
 * cantidad de elementos (22 medias ocupan lo mismo que 3), con veredicto de
 * la IA por miniatura y visor con navegación.
 */
export function MediaStrip({
    incidentId,
    media,
    assessments,
    requests,
    onMutated,
}: MediaStripProps) {
    const page = usePage();
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    const [openIndex, setOpenIndex] = useState<number | null>(null);
    const [requesting, setRequesting] = useState(false);

    const pendingRequest = requests.find((request) =>
        PENDING_REQUEST_STATUSES.includes(request.status ?? ''),
    );

    const assessmentFor = (item: IncidentMediaItem) =>
        assessments.find(
            (assessment) => assessment.mediaContextId === item.id,
        ) ?? null;

    const images = media.filter(
        (item) => item.mediaType === 'image' || item.mediaType === 'snapshot',
    ).length;
    const clips = media.length - images;

    const requestMedia = async () => {
        if (teamSlug === null) {
            toast.error('No hay equipo activo.');

            return;
        }

        setRequesting(true);

        try {
            const response = await postJson(
                `/${teamSlug}/incidents/${incidentId}/media/request`,
                {},
            );

            if (response.ok || response.status === 202) {
                toast.success(
                    'Media solicitada al proveedor. Llegará en unos minutos.',
                );
                onMutated();
            } else if (response.status === 403) {
                toast.error('No tienes permisos para solicitar media.');
            } else {
                toast.error(
                    (await readErrorMessage(response)) ??
                        'No se pudo solicitar la media.',
                );
            }
        } catch {
            toast.error('Error de red. Vuelve a intentarlo.');
        } finally {
            setRequesting(false);
        }
    };

    const openItem = openIndex !== null ? (media[openIndex] ?? null) : null;
    const openAssessment = openItem ? assessmentFor(openItem) : null;

    const navigate = (delta: number) => {
        if (openIndex === null || media.length === 0) {
            return;
        }

        setOpenIndex((openIndex + delta + media.length) % media.length);
    };

    return (
        <section>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-3xs font-semibold tracking-caps text-fg-3 uppercase">
                    Media del evento
                    {media.length > 0 && (
                        <span className="ml-1.5 font-mono text-fg-2 normal-case">
                            {images > 0 &&
                                `${images} ${images === 1 ? 'imagen' : 'imágenes'}`}
                            {images > 0 && clips > 0 && ' · '}
                            {clips > 0 &&
                                `${clips} ${clips === 1 ? 'clip' : 'clips'}`}
                        </span>
                    )}
                </h3>
                {pendingRequest ? (
                    <Badge variant="outline" className="gap-1 text-fg-2">
                        <Loader2 size={11} className="animate-spin" />
                        Solicitud en curso
                    </Badge>
                ) : (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={requestMedia}
                        disabled={requesting}
                    >
                        <Camera size={12} />
                        Solicitar media
                    </Button>
                )}
            </div>

            {media.length === 0 ? (
                <div className="flex flex-col items-center gap-2 rounded-md border border-dashed border-border py-6 text-fg-3">
                    <FileQuestion size={20} strokeWidth={1.5} />
                    <span className="text-xs">
                        Sin media disponible para este evento.
                    </span>
                </div>
            ) : (
                <div className="flex gap-2 overflow-x-auto pb-1.5">
                    {media.map((item, idx) => (
                        <MediaThumb
                            key={item.id}
                            item={item}
                            result={assessmentFor(item)?.result ?? null}
                            onOpen={() => setOpenIndex(idx)}
                        />
                    ))}
                </div>
            )}

            <Dialog
                open={openItem !== null}
                onOpenChange={(open) => !open && setOpenIndex(null)}
            >
                <DialogContent className="max-h-[85vh] max-w-3xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            Media #{openItem?.id} ·{' '}
                            {openItem?.mediaType ?? 'media'}
                            {openIndex !== null && media.length > 1 && (
                                <span className="ml-2 font-mono text-xs font-normal text-fg-3">
                                    {openIndex + 1} / {media.length}
                                </span>
                            )}
                        </DialogTitle>
                    </DialogHeader>
                    {openItem &&
                        openItem.url !== null &&
                        (isVideo(openItem) ? (
                            <video
                                src={openItem.url}
                                controls
                                autoPlay
                                className="max-h-[55vh] w-full rounded-md bg-black"
                            />
                        ) : (
                            <img
                                src={openItem.url}
                                alt={`Media del incidente #${openItem.id}`}
                                className="max-h-[55vh] w-full rounded-md object-contain"
                            />
                        ))}
                    {openAssessment && (
                        <div className="rounded-md border border-border bg-surface-2 p-3 text-xs">
                            <div className="mb-1 font-semibold text-fg-1">
                                Qué vio la IA:{' '}
                                {mediaResultLabel(openAssessment.result)}
                                {openAssessment.confidenceScore !== null &&
                                    ` (${Math.round(openAssessment.confidenceScore * 100)} %)`}
                            </div>
                            <p className="text-fg-2">
                                {openAssessment.summary ?? 'Sin resumen.'}
                            </p>
                        </div>
                    )}
                    {media.length > 1 && (
                        <div className="flex items-center justify-between">
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => navigate(-1)}
                            >
                                <ChevronLeft size={13} />
                                Anterior
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => navigate(1)}
                            >
                                Siguiente
                                <ChevronRight size={13} />
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
