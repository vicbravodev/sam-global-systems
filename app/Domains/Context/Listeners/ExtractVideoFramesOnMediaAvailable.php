<?php

namespace App\Domains\Context\Listeners;

use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Jobs\ExtractVideoFramesJob;

/**
 * Todo clip que queda disponible (adjunto inmediato, safety event con video,
 * media diferida descargada) pasa por `AttachImmediateEventMedia`, que emite
 * `EventMediaAvailable`. Aquí se engancha la extracción de fotogramas para
 * que el video también llegue al modelo de visión.
 *
 * Los fotogramas se registran como Snapshot, así que su propio
 * `EventMediaAvailable` no vuelve a entrar por aquí.
 */
class ExtractVideoFramesOnMediaAvailable
{
    public function handle(EventMediaAvailable $event): void
    {
        if (! (bool) config('media-frames.enabled', true)) {
            return;
        }

        $media = $event->media;

        if (! in_array($media->media_type, [MediaType::Clip, MediaType::Video], true)) {
            return;
        }

        ExtractVideoFramesJob::dispatch($media->id, $media->team_id)->afterCommit();
    }
}
