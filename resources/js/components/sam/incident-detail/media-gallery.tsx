import { usePage } from '@inertiajs/react';
import { Camera, Clapperboard, FileQuestion, Loader2 } from 'lucide-react';
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
import type {
    IncidentMediaAssessment,
    IncidentMediaItem,
    IncidentMediaRequestSummary,
} from '@/types/sam';

const PENDING_REQUEST_STATUSES = ['pending', 'sent', 'processing'];

const RESULT_LABEL: Record<string, string> = {
    confirms_event: 'Confirma el evento',
    contradicts_event: 'Contradice el evento',
    inconclusive: 'No concluyente',
    low_quality: 'Baja calidad',
    unavailable: 'No disponible',
};

function isVideo(item: IncidentMediaItem): boolean {
    return (
        item.mediaType === 'video' ||
        item.mediaType === 'clip' ||
        (item.mimeType ?? '').startsWith('video/')
    );
}

function MediaThumb({
    item,
    onOpen,
}: {
    item: IncidentMediaItem;
    onOpen: () => void;
}) {
    const video = isVideo(item);
    const preview = item.thumbnailUrl ?? (video ? null : item.url);

    return (
        <button
            type="button"
            onClick={onOpen}
            disabled={item.url === null}
            className="group relative flex aspect-video items-center justify-center overflow-hidden rounded-[6px] border border-border bg-surface-2 transition-colors hover:border-fg-3 disabled:cursor-not-allowed disabled:opacity-60"
            aria-label={`Abrir media #${item.id}`}
        >
            {preview ? (
                <img
                    src={preview}
                    alt={`Media del incidente #${item.id}`}
                    className="h-full w-full object-cover"
                />
            ) : (
                <span className="flex flex-col items-center gap-1 text-fg-3">
                    {video ? (
                        <Clapperboard size={20} strokeWidth={1.5} />
                    ) : (
                        <Camera size={20} strokeWidth={1.5} />
                    )}
                    <span className="text-[10px] uppercase">
                        {item.mediaType ?? 'media'}
                    </span>
                </span>
            )}
            {video && item.durationSeconds !== null && (
                <span className="absolute right-1 bottom-1 rounded bg-black/70 px-1 font-mono text-[10px] text-white">
                    {Math.floor(item.durationSeconds / 60)}:
                    {String(item.durationSeconds % 60).padStart(2, '0')}
                </span>
            )}
        </button>
    );
}

interface MediaGalleryProps {
    incidentId: number;
    media: IncidentMediaItem[];
    assessments: IncidentMediaAssessment[];
    requests: IncidentMediaRequestSummary[];
    onMutated: () => void;
}

export function MediaGallery({
    incidentId,
    media,
    assessments,
    requests,
    onMutated,
}: MediaGalleryProps) {
    const page = usePage();
    const teamSlug =
        (
            page.props as unknown as {
                currentTeam?: { slug?: string | null } | null;
            }
        ).currentTeam?.slug ?? null;

    const [openItem, setOpenItem] = useState<IncidentMediaItem | null>(null);
    const [requesting, setRequesting] = useState(false);

    const pendingRequest = requests.find((request) =>
        PENDING_REQUEST_STATUSES.includes(request.status ?? ''),
    );

    const assessmentFor = (item: IncidentMediaItem) =>
        assessments.find(
            (assessment) => assessment.mediaContextId === item.id,
        ) ?? null;

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

    const openAssessment = openItem ? assessmentFor(openItem) : null;

    return (
        <section className="rounded-[8px] border border-border bg-surface-1 p-4">
            <div className="mb-3 flex items-center justify-between gap-2">
                <h3 className="text-[13px] font-semibold tracking-[0.04em] text-fg-1 uppercase">
                    Media del evento
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
                <div className="flex flex-col items-center gap-2 rounded-[6px] border border-dashed border-border py-8 text-fg-3">
                    <FileQuestion size={20} strokeWidth={1.5} />
                    <span className="text-[12px]">
                        Sin media disponible para este evento.
                    </span>
                </div>
            ) : (
                <div className="grid grid-cols-2 gap-2 md:grid-cols-3">
                    {media.map((item) => (
                        <div key={item.id} className="flex flex-col gap-1">
                            <MediaThumb
                                item={item}
                                onOpen={() => setOpenItem(item)}
                            />
                            {assessmentFor(item) && (
                                <span className="truncate text-[11px] text-fg-3">
                                    IA:{' '}
                                    {RESULT_LABEL[
                                        assessmentFor(item)?.result ?? ''
                                    ] ?? assessmentFor(item)?.result}
                                </span>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <Dialog
                open={openItem !== null}
                onOpenChange={(open) => !open && setOpenItem(null)}
            >
                <DialogContent className="max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>
                            Media #{openItem?.id} ·{' '}
                            {openItem?.mediaType ?? 'media'}
                        </DialogTitle>
                    </DialogHeader>
                    {openItem &&
                        openItem.url !== null &&
                        (isVideo(openItem) ? (
                            <video
                                src={openItem.url}
                                controls
                                autoPlay
                                className="max-h-[60vh] w-full rounded-[6px] bg-black"
                            />
                        ) : (
                            <img
                                src={openItem.url}
                                alt={`Media del incidente #${openItem.id}`}
                                className="max-h-[60vh] w-full rounded-[6px] object-contain"
                            />
                        ))}
                    {openAssessment && (
                        <div className="rounded-[6px] border border-border bg-surface-2 p-3 text-[12px]">
                            <div className="mb-1 font-semibold text-fg-1">
                                Qué vio la IA —{' '}
                                {RESULT_LABEL[openAssessment.result ?? ''] ??
                                    openAssessment.result}
                                {openAssessment.confidenceScore !== null &&
                                    ` (${Math.round(openAssessment.confidenceScore * 100)}%)`}
                            </div>
                            <p className="text-fg-2">
                                {openAssessment.summary ?? 'Sin resumen.'}
                            </p>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
