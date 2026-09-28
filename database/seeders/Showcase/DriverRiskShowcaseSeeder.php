<?php

namespace Database\Seeders\Showcase;

use App\Domains\Drivers\Jobs\RecalculateDriverRiskProfilesJob;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Facades\DB;

/**
 * Perfil de riesgo de cada conductor, con la MISMA fórmula que
 * {@see RecalculateDriverRiskProfilesJob} (ventana de 30 días, pesos por
 * familia de evento + incidentes). No se llama al job directamente porque
 * recorre todos los tenants y actualiza perfiles existentes (y puede
 * notificar); aquí sólo se crea el perfil de los conductores que no tienen.
 */
class DriverRiskShowcaseSeeder extends ShowcaseStep
{
    private const HARSH = ['harsh_braking', 'harsh_acceleration', 'harsh_turn', 'speeding', 'following_distance', 'aggressive_driving'];

    public function run(): void
    {
        $since = $this->ctx->now->subDays(RecalculateDriverRiskProfilesJob::WINDOW_DAYS);
        $withProfile = array_flip(DB::table('driver_risk_profiles')->whereIn('driver_id', $this->ctx->drivers->pluck('id'))->pluck('driver_id')->all());

        $counts = NormalizedEvent::query()
            ->where('normalized_events.team_id', $this->ctx->team->id)
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('driver_id')
            ->join('event_types', 'event_types.id', '=', 'normalized_events.event_type_id')
            ->selectRaw('driver_id, event_types.code as code, count(*) as total')
            ->groupBy('driver_id', 'event_types.code')
            ->get()
            ->groupBy('driver_id');

        $incidents = Incident::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('opened_at', '>=', $since)
            ->whereNotNull('driver_id')
            ->selectRaw('driver_id, count(*) as total')
            ->groupBy('driver_id')
            ->pluck('total', 'driver_id');

        $rows = [];

        /** @var Driver $driver */
        foreach ($this->ctx->drivers as $driver) {
            if (isset($withProfile[$driver->id])) {
                continue;
            }

            $byCode = collect($counts->get($driver->id, []))->pluck('total', 'code')->map(fn ($n) => (int) $n);
            $harsh = $byCode->only(self::HARSH)->sum();
            $fatigue = $byCode->only(RecalculateDriverRiskProfilesJob::FATIGUE_CODES)->sum();
            $severe = $byCode->only(RecalculateDriverRiskProfilesJob::SEVERE_CODES)->sum();
            $other = max(0, $byCode->sum() - $harsh - $fatigue - $severe);
            $incidentCount = (int) ($incidents[$driver->id] ?? 0);
            $score = min(100.0, round($harsh * 4.0 + $fatigue * 8.0 + $severe * 15.0 + $other * 2.0 + $incidentCount * 10.0, 2));

            $rows[] = [
                'driver_id' => $driver->id,
                'risk_score' => $score,
                'risk_level' => match (true) {
                    $score >= 75 => 'critical',
                    $score >= 50 => 'high',
                    $score >= 25 => 'medium',
                    default => 'low',
                },
                'incidents_count' => $incidentCount,
                'harsh_events_count' => $harsh,
                'fatigue_flags_count' => $fatigue,
                'last_calculated_at' => $this->ctx->now->subHours(2),
                'metadata_json' => ['showcase' => true, 'trend' => 'baseline', 'window_days' => RecalculateDriverRiskProfilesJob::WINDOW_DAYS],
            ];
        }

        $this->bulkInsert('driver_risk_profiles', $rows);
    }
}
