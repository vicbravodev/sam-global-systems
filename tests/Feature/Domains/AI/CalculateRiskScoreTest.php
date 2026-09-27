<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\CalculateRiskScore;
use App\Domains\Context\Enums\RiskLevel;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El score de riesgo lee cada fuente de donde realmente vive (perfil
 * operacional, snapshot de historial reciente y signals_json de
 * SignalsBuilder), no de claves inexistentes que lo dejaban en la base.
 */
class CalculateRiskScoreTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    public function test_severity_is_the_base_without_context(): void
    {
        $event = $this->event('critical');

        $this->assertSame(0.6, app(CalculateRiskScore::class)->execute($event, null));
    }

    public function test_severity_falls_back_to_the_event_severity_relation(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => EventSeverity::factory()->high()->create()->id,
            'payload_normalized_json' => [],
        ]);

        $this->assertSame(0.45, app(CalculateRiskScore::class)->execute($event, null));
    }

    public function test_operational_profile_risk_level_is_read_from_the_profile_table(): void
    {
        $event = $this->event('medium');
        $snapshot = $this->snapshot($event);

        OperationalContextProfile::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'risk_level' => RiskLevel::High,
        ]);

        $this->assertSame(0.5, app(CalculateRiskScore::class)->execute($event, $snapshot));
    }

    public function test_recurrence_is_read_from_the_recent_history_snapshot(): void
    {
        $event = $this->event('medium');
        $snapshot = $this->snapshot($event, recentHistory: ['recent_events_count' => 12]);

        $this->assertSame(0.45, app(CalculateRiskScore::class)->execute($event, $snapshot));
    }

    public function test_sensitive_geofence_uses_the_real_signal_key(): void
    {
        $event = $this->event('medium');
        $snapshot = $this->snapshot($event, signals: ['is_in_sensitive_geofence' => true]);

        $this->assertSame(0.45, app(CalculateRiskScore::class)->execute($event, $snapshot));
    }

    #[DataProvider('correlationSignals')]
    public function test_correlation_signals_boost_risk(string $signal): void
    {
        $event = $this->event('medium');
        $snapshot = $this->snapshot($event, signals: [$signal => true]);

        $this->assertSame(0.4, app(CalculateRiskScore::class)->execute($event, $snapshot));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function correlationSignals(): array
    {
        return [
            'pánico repetido en 24h' => ['repeated_panic_24h'],
            'manejo brusco cerca del evento' => ['harsh_driving_near_event'],
            'gps perdido en movimiento' => ['gps_lost_in_motion'],
        ];
    }

    public function test_critical_panic_in_sensitive_geofence_with_high_risk_profile_is_urgent(): void
    {
        $event = $this->event('critical');
        $snapshot = $this->snapshot($event, signals: ['is_in_sensitive_geofence' => true]);

        OperationalContextProfile::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'risk_level' => RiskLevel::High,
        ]);

        $this->assertGreaterThanOrEqual(0.85, app(CalculateRiskScore::class)->execute($event, $snapshot));
    }

    public function test_score_is_clamped_to_one(): void
    {
        $event = $this->event('critical');
        $snapshot = $this->snapshot(
            $event,
            signals: [
                'is_in_sensitive_geofence' => true,
                'repeated_panic_24h' => true,
                'harsh_driving_near_event' => true,
                'gps_lost_in_motion' => true,
            ],
            recentHistory: ['recent_events_count' => 20],
        );

        OperationalContextProfile::factory()->critical()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        $this->assertSame(1.0, app(CalculateRiskScore::class)->execute($event, $snapshot));
    }

    private function event(string $severity): NormalizedEvent
    {
        return NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'payload_normalized_json' => ['severity_code' => $severity],
        ]);
    }

    /**
     * @param  array<string, bool>  $signals
     * @param  array<string, mixed>|null  $recentHistory
     */
    private function snapshot(NormalizedEvent $event, array $signals = [], ?array $recentHistory = null): EventContextSnapshot
    {
        return EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'signals_json' => $signals,
            'recent_history_snapshot_json' => $recentHistory,
        ]);
    }
}
