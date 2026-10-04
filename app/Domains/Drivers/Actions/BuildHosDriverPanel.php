<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Pestaña HOS del chofer: su último estado (relojes), los episodios abiertos
 * y los últimos cerrados, cada uno con los avisos que SAM le mandó (fuente
 * `hos_episode`) con canal y resultado de cada entrega, y su incidente.
 */
class BuildHosDriverPanel
{
    public const int HISTORY_LIMIT = 20;

    /**
     * @return array{state: array<string, mixed>|null, openEpisodes: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    public function execute(Driver $driver, CarbonInterface $now): array
    {
        return TenantContext::for($driver->team_id, function () use ($driver, $now): array {
            $teamId = $driver->team_id;

            $state = HosDriverState::query()
                ->where('team_id', $teamId)
                ->where('driver_id', $driver->id)
                ->with('asset')
                ->first();

            $open = HosEpisode::query()
                ->where('team_id', $teamId)
                ->where('driver_id', $driver->id)
                ->open()
                ->with('incident')
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->get();

            $history = HosEpisode::query()
                ->where('team_id', $teamId)
                ->where('driver_id', $driver->id)
                ->whereNotNull('resolved_at')
                ->with('incident')
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->limit(self::HISTORY_LIMIT)
                ->get();

            $nudges = $this->nudges($teamId, $open->concat($history));
            $present = fn (HosEpisode $episode): array => $this->episode($episode, $nudges[$episode->id] ?? []);

            return [
                'state' => $state === null ? null : [
                    'dutyStatus' => $state->duty_status?->value,
                    'statusSince' => $state->status_since?->toIso8601String(),
                    'appDisconnectedSince' => $state->app_disconnected_since?->toIso8601String(),
                    'observedAt' => $state->observed_at->toIso8601String(),
                    'stale' => $state->observed_at->lt($now->toImmutable()->subSeconds(ListHosFleet::STALE_SECONDS)),
                    'asset' => $state->asset !== null ? [
                        'id' => $state->asset->id,
                        'name' => $state->asset->name,
                        'code' => $state->asset->code,
                    ] : null,
                    'clocks' => $state->clockSnapshot(),
                    'violationSeconds' => $state->violation_s,
                ],
                'openEpisodes' => array_values($open->map($present)->all()),
                'history' => array_values($history->map($present)->all()),
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $nudges
     * @return array<string, mixed>
     */
    private function episode(HosEpisode $episode, array $nudges): array
    {
        $incident = $episode->incident;

        return [
            'id' => $episode->id,
            'situation' => $episode->situation->value,
            'openedAt' => $episode->opened_at->toIso8601String(),
            'resolvedAt' => $episode->resolved_at?->toIso8601String(),
            'resolution' => $episode->resolution?->value,
            'ladderStep' => $episode->ladder_step,
            'nextNudgeAt' => $episode->next_nudge_at?->toIso8601String(),
            'escalatedAt' => $episode->escalated_at?->toIso8601String(),
            // attachIncident ya valida el team; se vuelve a mirar al leer.
            'incident' => $incident !== null && $incident->team_id === $episode->team_id
                ? ['id' => $incident->id, 'reference' => $incident->reference()]
                : null,
            'nudges' => $nudges,
        ];
    }

    /**
     * Avisos HOS de esos episodios, del más viejo al más nuevo.
     *
     * @param  Collection<int, HosEpisode>  $episodes
     * @return array<int, list<array<string, mixed>>> por id de episodio
     */
    private function nudges(int $teamId, Collection $episodes): array
    {
        if ($episodes->isEmpty()) {
            return [];
        }

        $notifications = Notification::query()
            ->where('team_id', $teamId)
            ->where('source_type', NotificationSourceType::HosEpisode)
            ->whereIn('source_reference_id', $episodes->map(fn (HosEpisode $episode): string => (string) $episode->id)->all())
            ->with([
                'deliveries' => fn (Relation $query) => $query->where('team_id', $teamId)->orderBy('id'),
                'deliveries.channel',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $grouped = [];

        foreach ($notifications as $notification) {
            $payload = is_array($notification->payload_json) ? $notification->payload_json : [];
            $hos = isset($payload['hos']) && is_array($payload['hos']) ? $payload['hos'] : [];

            $grouped[(int) $notification->source_reference_id][] = [
                'id' => $notification->id,
                'step' => isset($hos['step']) && is_numeric($hos['step']) ? (int) $hos['step'] : null,
                'notice' => isset($hos['notice']) && is_string($hos['notice']) ? $hos['notice'] : null,
                'createdAt' => $notification->created_at?->toIso8601String(),
                'deliveries' => array_values($notification->deliveries->map(fn (NotificationDelivery $delivery): array => [
                    'channel' => $delivery->channel?->channel_type?->value,
                    'status' => $delivery->status->value,
                ])->all()),
            ];
        }

        return $grouped;
    }
}
