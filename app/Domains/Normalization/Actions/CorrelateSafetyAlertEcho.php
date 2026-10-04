<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Enlaza el eco de un safety event (un `AlertIncident` que Samsara dispara POR
 * ese safety event, tipo `provider_safety_alert`) con el safety event del poll:
 * misma unidad del mismo tenant, a ± `pipeline.safety_alert_echo.window_seconds`.
 * El enlace vive en el eco (`echo_of_normalized_event_id`) y se intenta desde
 * los dos lados, porque el webhook suele llegar antes que el poll. Si el eco
 * no encuentra su safety event, adelanta el poll de la integración.
 */
class CorrelateSafetyAlertEcho
{
    public const string ECHO_TYPE_CODE = 'provider_safety_alert';

    public const string LINK_KEY = 'echo_of_normalized_event_id';

    public function execute(NormalizedEvent $event): void
    {
        $kind = $this->kind($event);

        if ($kind === null) {
            return;
        }

        $input = ['normalized_event_id' => $event->id, 'kind' => $kind];
        $window = max(0, (int) config('pipeline.safety_alert_echo.window_seconds', 120));

        if ($event->asset_id === null) {
            SystemLog::skipped('normalization.safety_echo.unmatched', reason: 'no_asset', input: $input, calc: ['window_seconds' => $window]);

            return;
        }

        if ($kind === 'echo') {
            $this->correlateEcho($event, $window, $input);

            return;
        }

        $this->correlateSafetyEvent($event, $window, $input);
    }

    /**
     * @return 'echo'|'safety_event'|null
     */
    private function kind(NormalizedEvent $event): ?string
    {
        if (($event->payload_normalized_json['event_type_code'] ?? null) === self::ECHO_TYPE_CODE) {
            return 'echo';
        }

        $isSafetyFeed = $event->rawEvent()
            ->where('team_id', $event->team_id)
            ->where('deduplication_key', 'like', IngestSafetyEvent::KEY_PREFIX.'%')
            ->exists();

        return $isSafetyFeed ? 'safety_event' : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function correlateEcho(NormalizedEvent $echo, int $window, array $input): void
    {
        $at = Carbon::instance($echo->occurred_at);

        $candidate = $this->nearby($echo, $window)
            ->whereHas('rawEvent', fn (Builder $raw) => $raw
                ->where('team_id', $echo->team_id)
                ->where('deduplication_key', 'like', IngestSafetyEvent::KEY_PREFIX.'%'))
            ->get()
            ->sortBy(fn (NormalizedEvent $e) => abs($at->diffInSeconds($e->occurred_at)))
            ->first();

        if ($candidate === null) {
            SystemLog::skipped('normalization.safety_echo.unmatched', reason: 'no_candidate', input: $input, calc: ['window_seconds' => $window]);
            $this->requestPoll($echo);

            return;
        }

        $this->link($echo, $candidate, 'event_first', $window);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function correlateSafetyEvent(NormalizedEvent $event, int $window, array $input): void
    {
        $echoes = $this->nearby($event, $window)
            ->whereHas('eventType', fn (Builder $type) => $type->where('code', self::ECHO_TYPE_CODE))
            ->get()
            ->filter(fn (NormalizedEvent $echo) => ! isset($echo->payload_normalized_json[self::LINK_KEY]));

        if ($echoes->isEmpty()) {
            SystemLog::skipped('normalization.safety_echo.unmatched', reason: 'no_candidate', input: $input, calc: ['window_seconds' => $window], debug: true);

            return;
        }

        foreach ($echoes as $echo) {
            $this->link($echo, $event, 'alert_first', $window);
        }
    }

    /**
     * Mismo tenant, misma unidad, dentro de la ventana, sin el propio evento.
     *
     * @return Builder<NormalizedEvent>
     */
    private function nearby(NormalizedEvent $event, int $window): Builder
    {
        $at = Carbon::instance($event->occurred_at);

        return NormalizedEvent::query()
            ->where('team_id', $event->team_id)
            ->where('asset_id', $event->asset_id)
            ->whereKeyNot($event->id)
            ->whereBetween('occurred_at', [$at->copy()->subSeconds($window), $at->copy()->addSeconds($window)]);
    }

    private function link(NormalizedEvent $echo, NormalizedEvent $safetyEvent, string $direction, int $window): void
    {
        $echo->forceFill([
            'payload_normalized_json' => [...$echo->payload_normalized_json, self::LINK_KEY => $safetyEvent->id],
        ])->save();

        SystemLog::ok('normalization.safety_echo.correlated', input: [
            'echo_normalized_event_id' => $echo->id,
            'safety_normalized_event_id' => $safetyEvent->id,
        ], calc: [
            'direction' => $direction,
            'seconds_apart' => (int) abs(Carbon::instance($echo->occurred_at)->diffInSeconds($safetyEvent->occurred_at)),
            'window_seconds' => $window,
        ]);
    }

    /**
     * El webhook llegó antes que el poll: se pide el poll de safety events de
     * la integración del tenant, con un retraso para que Samsara alcance a
     * publicarlo. El job es único por integración: una ráfaga no lo multiplica.
     */
    private function requestPoll(NormalizedEvent $echo): void
    {
        $input = ['normalized_event_id' => $echo->id];

        $integration = TenantIntegration::query()
            ->where('team_id', $echo->team_id)
            ->where('provider_id', $echo->provider_id)
            ->where('status', TenantIntegrationStatus::Active)
            ->ofLiveTeam()
            ->orderBy('id')
            ->first();

        if ($integration === null) {
            SystemLog::skipped('normalization.safety_echo.poll_requested', reason: 'no_active_integration', input: $input);

            return;
        }

        $delay = max(0, (int) config('pipeline.safety_alert_echo.poll_delay_seconds', 30));

        PollSafetyEventsJob::dispatch($integration)->delay(now()->addSeconds($delay));

        SystemLog::ok('normalization.safety_echo.poll_requested', input: [...$input, 'integration_id' => $integration->id], calc: ['delay_seconds' => $delay]);
    }
}
