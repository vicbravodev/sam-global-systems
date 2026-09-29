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
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * El score de riesgo lee cada fuente de donde realmente vive (perfil
 * operacional, snapshot de historial reciente y signals_json de
 * SignalsBuilder), no de claves inexistentes que lo dejaban en la base.
 */
class CalculateRiskScoreTest extends TestCase
{
    use AssertsSystemLog;
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

        $ctx = $this->assertSystemLogged('ai.risk.calculated');
        $this->assertSame($event->id, $ctx['input']['normalized_event_id']);
        $this->assertNull($ctx['input']['snapshot_id']);
        $this->assertFalse($ctx['calc']['snapshot_present']);
        $this->assertSame('critical', $ctx['calc']['severity_code']);
        $this->assertSame('payload', $ctx['calc']['severity_source']);
        $this->assertSame(0.0, $ctx['calc']['risk_level_boost']);
        $this->assertSame(0.0, $ctx['calc']['recurrence_boost']);
        $this->assertSame(0.0, $ctx['calc']['geofence_boost']);
        $this->assertSame(0.0, $ctx['calc']['signal_boost_total']);
        $this->assertSame($ctx['calc']['base'], $ctx['calc']['final']);
        $this->assertSame(0.6, $ctx['result']['risk_score']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_risk_calculation_logs_every_term_and_is_recomputable(): void
    {
        $event = $this->event('critical');
        $snapshot = $this->snapshot(
            $event,
            signals: ['is_in_sensitive_geofence' => true, 'repeated_panic_24h' => true],
            recentHistory: ['recent_events_count' => 3],
        );

        OperationalContextProfile::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'risk_level' => RiskLevel::High,
        ]);

        $score = app(CalculateRiskScore::class)->execute($event, $snapshot);

        $ctx = $this->assertSystemLogged('ai.risk.calculated');
        $calc = $ctx['calc'];

        $this->assertSame($event->id, $ctx['input']['normalized_event_id']);
        $this->assertSame($snapshot->id, $ctx['input']['snapshot_id']);
        $this->assertTrue($calc['snapshot_present']);
        $this->assertSame('critical', $calc['severity_code']);
        $this->assertSame('payload', $calc['severity_source']);
        $this->assertSame(0.6, $calc['base']);
        $this->assertSame('high', $calc['risk_level']);
        $this->assertSame(0.2, $calc['risk_level_boost']);
        $this->assertSame(3, $calc['recent_events_count']);
        $this->assertSame(['high' => 10, 'medium' => 3], $calc['recurrence_thresholds']);
        $this->assertSame(0.08, $calc['recurrence_boost']);
        $this->assertTrue($calc['sensitive_geofence']);
        $this->assertSame(0.15, $calc['geofence_boost']);
        $this->assertSame([
            'repeated_panic_24h' => 0.1,
            'harsh_driving_near_event' => 0.0,
            'gps_lost_in_motion' => 0.0,
        ], $calc['signal_boosts']);
        $this->assertSame(0.1, $calc['signal_boost_total']);
        $this->assertSame([0.0, 1.0], $calc['clamp']);

        $recomputed = round(max($calc['clamp'][0], min($calc['clamp'][1],
            $calc['base'] + $calc['risk_level_boost'] + $calc['recurrence_boost'] + $calc['geofence_boost'] + $calc['signal_boost_total'],
        )), 2);

        $this->assertSame($recomputed, $calc['final']);
        $this->assertSame($calc['final'], $score);
        $this->assertSame($score, $ctx['result']['risk_score']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_severity_falls_back_to_the_event_severity_relation(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_severity_id' => EventSeverity::factory()->high()->create()->id,
            'payload_normalized_json' => [],
        ]);

        $this->assertSame(0.45, app(CalculateRiskScore::class)->execute($event, null));

        $ctx = $this->assertSystemLogged('ai.risk.calculated');
        $this->assertSame('event_severity', $ctx['calc']['severity_source']);
        $this->assertSame('high', $ctx['calc']['severity_code']);
        $this->assertSame(0.45, $ctx['calc']['final']);
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

        $ctx = $this->assertSystemLogged('ai.risk.calculated');
        $this->assertGreaterThan(1.0, $ctx['calc']['sum']);
        $this->assertSame(1.0, $ctx['calc']['final']);
        $this->assertSame('critical', $ctx['calc']['risk_level']);
        $this->assertSame(0.35, $ctx['calc']['risk_level_boost']);
        $this->assertSame(0.15, $ctx['calc']['recurrence_boost']);
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
