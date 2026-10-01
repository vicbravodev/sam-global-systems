<?php

namespace Database\Seeders\Showcase\Support;

use App\Domains\Normalization\Models\NormalizedEvent;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recorre los eventos normalizados del tenant dentro de la ventana simulada
 * — reales (Samsara) y simulados por igual — y les asigna su escenario.
 */
final class ShowcaseEvents
{
    /** @var array<int, string> */
    private array $skipCategories;

    public function __construct(private readonly ShowcaseContext $ctx)
    {
        /** @var array<int, string> $skip */
        $skip = config('ai.skip_evaluation_categories', ['safety', 'maintenance']);
        $this->skipCategories = $skip;
    }

    /**
     * @return Builder<NormalizedEvent>
     */
    public function query(): Builder
    {
        return NormalizedEvent::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('occurred_at', '>=', $this->ctx->startDay())
            ->where('occurred_at', '<=', $this->ctx->now)
            ->whereNotIn('status', ['unmapped', 'failed']);
    }

    /**
     * @param  Builder<NormalizedEvent>  $query
     * @param  Closure(Collection<int, NormalizedEvent>): void  $callback
     */
    public function chunk(Builder $query, Closure $callback, int $size = 300): void
    {
        $query->with(['eventType', 'eventCategory', 'eventSeverity', 'asset', 'driver'])
            ->chunkById($size, $callback);
    }

    public function scenario(NormalizedEvent $event): EventScenario
    {
        return ShowcaseEventCatalog::scenarioFor(
            $event->id,
            (string) $event->eventType?->code,
            (string) $event->eventCategory?->code,
            $this->skipCategories,
        );
    }

    /**
     * @return array<int, string>
     */
    public function skipCategories(): array
    {
        return $this->skipCategories;
    }

    /**
     * Ubicación del evento: la del payload normalizado, o la casa del activo.
     *
     * @return array{0: string, 1: float, 2: float}
     */
    public function location(NormalizedEvent $event): array
    {
        $loc = $event->payload_normalized_json['location'] ?? null;
        $home = $event->asset_id !== null ? ($this->ctx->homes[$event->asset_id] ?? null) : null;

        if (is_array($loc) && isset($loc['latitude'], $loc['longitude'])) {
            return [
                (string) ($loc['formatted_location'] ?? $home[0] ?? 'Sin dirección'),
                (float) $loc['latitude'],
                (float) $loc['longitude'],
            ];
        }

        return $home ?? ['Sin dirección', 25.6866, -100.3161];
    }
}
