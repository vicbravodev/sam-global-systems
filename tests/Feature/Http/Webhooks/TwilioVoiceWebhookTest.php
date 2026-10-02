<?php

namespace Tests\Feature\Http\Webhooks;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Roadmap V2-A3: DTMF gather (1 = real, 2 = falsa alarma) and call status
 * callbacks for the operator verification call.
 */
class TwilioVoiceWebhookTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private const AUTH_TOKEN = 'voice-tok-789';

    private Team $team;

    private NotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        $this->team = User::factory()->create()->currentTeam;

        // Voice webhooks are signed by SAM's platform Twilio account.
        config()->set('services.twilio.account_sid', 'AC-voice');
        config()->set('services.twilio.auth_token', self::AUTH_TOKEN);

        $this->channel = NotificationChannel::factory()->voice()->create([
            'config_json' => ['from' => '+15005550006'],
        ]);
    }

    private function makeVerification(array $attributes = []): IncidentCallVerification
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        return IncidentCallVerification::factory()->calling()->create(array_merge([
            'team_id' => $this->team->id,
            'incident_id' => $incident->id,
            'notification_channel_id' => $this->channel->id,
            'phone' => '+5215512345678',
        ], $attributes));
    }

    private function inputOf(IncidentCallVerification $verification): array
    {
        return ['verification_id' => $verification->id, 'incident_id' => $verification->incident_id, 'attempt' => $verification->attempt];
    }

    private function assertNoPhoneLogged(): void
    {
        $json = json_encode($this->systemLogEntries());

        $this->assertStringNotContainsString('5215512345678', $json);
        $this->assertNoSensitiveDataLogged();
    }

    private function postSigned(string $path, array $params, ?string $authToken = self::AUTH_TOKEN): TestResponse
    {
        $url = url($path);

        $signature = $authToken !== null
            ? (new RequestValidator($authToken))->computeSignature($url, $params)
            : 'forged-signature';

        return $this->post($path, $params, ['X-Twilio-Signature' => $signature]);
    }

    private function gather(IncidentCallVerification $verification, string $digits, ?string $authToken = self::AUTH_TOKEN): TestResponse
    {
        return $this->postSigned(
            "/api/webhooks/twilio/voice/{$verification->id}/gather",
            ['CallSid' => (string) $verification->call_sid, 'Digits' => $digits],
            $authToken,
        );
    }

    private function postStatus(IncidentCallVerification $verification, string $callStatus): TestResponse
    {
        return $this->postSigned(
            "/api/webhooks/twilio/voice/{$verification->id}/status",
            ['CallSid' => (string) $verification->call_sid, 'CallStatus' => $callStatus],
        );
    }

    public function test_digit_1_confirms_the_emergency_without_acknowledging_it(): void
    {
        $verification = $this->makeVerification();

        $response = $this->gather($verification, '1');

        $response->assertOk();
        $this->assertStringContainsString('Emergencia confirmada', $response->getContent());

        $fresh = $verification->fresh();
        $this->assertSame(CallVerificationStatus::Answered, $fresh->status);
        $this->assertSame(CallVerificationOutcome::ConfirmedReal, $fresh->outcome);
        $this->assertSame('1', $fresh->digits_received);
        $this->assertNotNull($fresh->responded_at);

        // Quien contesta suele ser el chofer: confirmar no es atender. La
        // escalera sigue viva hasta que alguien del equipo reconozca o tome.
        $incident = Incident::withoutGlobalScopes()->find($verification->incident_id);
        $this->assertNull($incident->acknowledged_at);

        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', 'verification_call')
            ->count());

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('action', 'incident.call_verification.confirmed_real')
            ->count());

        $this->assertSystemLogged('incidents.call_verification.answered', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input'] === $this->inputOf($verification)
            && $c['result'] === ['outcome' => CallVerificationOutcome::ConfirmedReal->value, 'acknowledged' => false, 'escalated' => true, 'level_requested' => 0]);
        $this->assertNoPhoneLogged();
    }

    public function test_digit_2_closes_the_incident_as_false_alarm(): void
    {
        $verification = $this->makeVerification();

        $response = $this->gather($verification, '2');

        $response->assertOk();
        $this->assertStringContainsString('falsa alarma', $response->getContent());

        $fresh = $verification->fresh();
        $this->assertSame(CallVerificationOutcome::ConfirmedFalse, $fresh->outcome);

        $incident = Incident::withoutGlobalScopes()->with('status')->find($verification->incident_id);
        $this->assertSame(IncidentStatusCode::FalsePositive->value, $incident->status?->code);

        $this->assertSystemLogged('incidents.call_verification.answered', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input'] === $this->inputOf($verification)
            && $c['result'] === ['outcome' => CallVerificationOutcome::ConfirmedFalse->value, 'closed' => true, 'resolution_code' => ResolutionCode::FalsePositive->value]);
        $this->assertNoPhoneLogged();
    }

    public function test_invalid_digit_replays_the_prompt(): void
    {
        $verification = $this->makeVerification();

        $response = $this->gather($verification, '9');

        $response->assertOk();
        $this->assertStringContainsString('<Gather', $response->getContent());
        $this->assertNull($verification->fresh()->outcome);

        $this->assertSystemLogged('incidents.call_verification.answered', fn (array $c) => $c['reason'] === 'invalid_digit'
            && $c['input'] === $this->inputOf($verification)
            && $c['calc'] === ['digits_length' => 1]);
        $this->assertNoPhoneLogged();
    }

    public function test_invalid_signature_is_rejected_with_403(): void
    {
        $verification = $this->makeVerification();

        $this->gather($verification, '1', authToken: null)->assertForbidden();

        $this->assertSystemLogged('webhook.twilio.signature_rejected', fn (array $c) => $c['reason'] === 'hmac_mismatch' && $c['input']['endpoint'] === 'call_verification');
        $this->assertNoSensitiveDataLogged();
        $this->assertNull($verification->fresh()->outcome);
    }

    public function test_unknown_verification_is_a_404(): void
    {
        $this->post('/api/webhooks/twilio/voice/99999/gather', ['Digits' => '1'])
            ->assertNotFound();
    }

    public function test_second_answer_is_idempotent(): void
    {
        $verification = $this->makeVerification();

        $this->gather($verification, '1')->assertOk();
        $ackAt = Incident::withoutGlobalScopes()->find($verification->incident_id)->acknowledged_at;

        $response = $this->gather($verification, '2');

        $response->assertOk();
        $this->assertStringContainsString('Ya registramos su respuesta', $response->getContent());

        $this->assertSame(CallVerificationOutcome::ConfirmedReal, $verification->fresh()->outcome);

        $incident = Incident::withoutGlobalScopes()->with('status')->find($verification->incident_id);
        $this->assertEquals($ackAt, $incident->acknowledged_at);
        $this->assertNotSame(IncidentStatusCode::FalsePositive->value, $incident->status?->code);

        $this->assertSystemLogged('incidents.call_verification.answered', fn (array $c) => ($c['reason'] ?? null) === 'already_answered'
            && $c['input'] === $this->inputOf($verification));
        $this->assertNoPhoneLogged();
    }

    public function test_unanswered_status_chains_the_next_attempt(): void
    {
        $verification = $this->makeVerification();

        $this->postStatus($verification, 'no-answer')->assertNoContent();

        $this->assertSame(CallVerificationStatus::NoAnswer, $verification->fresh()->status);

        $next = IncidentCallVerification::withoutGlobalScopes()
            ->where('incident_id', $verification->incident_id)
            ->where('attempt', 2)
            ->sole();

        Queue::assertPushed(
            PlaceVerificationCallJob::class,
            fn (PlaceVerificationCallJob $job) => $job->verificationId === $next->id,
        );

        $this->assertSystemLogged('incidents.call_verification.attempt_failed', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input'] === $this->inputOf($verification)
            && $c['calc']['failure_code'] === 'call_status'
            && $c['calc']['call_status'] === 'no-answer'
            && $c['result'] === ['next' => 'next_attempt', 'next_attempt' => 2, 'next_attempt_created' => true]);
        $this->assertSystemNotLogged('incidents.call_verification.status_ignored');
        $this->assertNoPhoneLogged();
    }

    public function test_unanswered_last_attempt_escalates_the_incident(): void
    {
        $verification = $this->makeVerification(['attempt' => 3]);

        $this->postStatus($verification, 'no-answer')->assertNoContent();

        $fresh = $verification->fresh();
        $this->assertSame(CallVerificationOutcome::NoAnswer, $fresh->outcome);

        $incident = Incident::withoutGlobalScopes()->with('status')->find($verification->incident_id);
        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->status?->code);

        Queue::assertNotPushed(PlaceVerificationCallJob::class);
    }

    public function test_status_callback_after_an_answer_is_a_no_op(): void
    {
        $verification = $this->makeVerification();

        $this->gather($verification, '1')->assertOk();
        $this->postStatus($verification, 'completed')->assertNoContent();

        $this->assertSame(CallVerificationOutcome::ConfirmedReal, $verification->fresh()->outcome);
        $this->assertSame(1, IncidentCallVerification::withoutGlobalScopes()->count());

        $entry = collect($this->systemLogEntries('incidents.call_verification.status_ignored'))->sole();
        $this->assertSame('debug', $entry['level']);
        $this->assertSame('not_in_flight', $entry['context']['reason']);
        $this->assertSame($this->inputOf($verification), $entry['context']['input']);
        $this->assertSame(['call_status' => 'completed'], $entry['context']['calc']);
        $this->assertNoPhoneLogged();
    }

    public function test_a_non_final_status_is_ignored_while_the_call_rings(): void
    {
        $verification = $this->makeVerification();

        $this->postStatus($verification, 'ringing')->assertNoContent();

        $this->assertSame(CallVerificationStatus::Calling, $verification->fresh()->status);
        $this->assertSystemLogged('incidents.call_verification.status_ignored', fn (array $c) => $c['reason'] === 'status_not_final'
            && $c['calc'] === ['call_status' => 'ringing']);
        $this->assertSystemNotLogged('incidents.call_verification.attempt_failed');
    }

    public function test_terminal_incident_answers_politely_without_acting(): void
    {
        $incident = Incident::factory()->closed()->create(['team_id' => $this->team->id]);

        $verification = IncidentCallVerification::factory()->calling()->create([
            'team_id' => $this->team->id,
            'incident_id' => $incident->id,
            'notification_channel_id' => $this->channel->id,
        ]);

        $response = $this->gather($verification, '1');

        $response->assertOk();
        $this->assertStringContainsString('ya está cerrado', $response->getContent());
        $this->assertSame(CallVerificationStatus::Answered, $verification->fresh()->status);

        $this->assertSystemLogged('incidents.call_verification.answered', fn (array $c) => $c['reason'] === 'incident_closed'
            && $c['input'] === $this->inputOf($verification)
            && $c['result'] === ['outcome' => CallVerificationOutcome::ConfirmedReal->value]);
        $this->assertNoPhoneLogged();
    }

    /**
     * Decisión 2026-09-28: el 1 ("es real") no sólo reconoce — escala el
     * incidente y avisa en ese momento al primer nivel con prioridad crítica.
     */
    public function test_digit_1_escalates_and_notifies_the_first_level_right_away(): void
    {
        $verification = $this->makeVerification();

        $this->gather($verification, '1')->assertOk();

        $incident = Incident::withoutGlobalScopes()->with('status')->find($verification->incident_id);
        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->status->code);

        $notice = Notification::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('notification_type', 'incident.emergency_confirmed')
            ->sole();

        $this->assertSame(NotificationPriority::Critical, $notice->priority);
        $this->assertSame((string) $incident->id, $notice->source_reference_id);
    }

    public function test_digit_1_keeps_the_ladder_running_from_the_next_step(): void
    {
        $verification = $this->makeVerification();
        TenantEscalationConfig::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'steps_json' => [
                ['delay_minutes' => 0, 'contacts' => ['+5215500000001']],
                ['delay_minutes' => 5, 'contacts' => ['+5215500000002']],
            ],
        ]);

        $this->gather($verification, '1')->assertOk();

        // El aviso inmediato cuenta como el nivel 0; el nivel 1 queda
        // programado y sólo un humano del equipo lo detiene.
        $incident = Incident::withoutGlobalScopes()->find($verification->incident_id);
        $this->assertNull($incident->acknowledged_at);
        $this->assertSame(1, $incident->escalation_level);
        $this->assertNotNull($incident->next_escalation_at);
        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn (CheckIncidentAcknowledgementJob $job) => $job->incidentId === $incident->id
            && $job->level === 1);

        $this->assertSystemLogged('incidents.escalation.accelerated', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input'] === ['incident_id' => $incident->id, 'accelerate_reason' => 'emergency_confirmed']);
        $this->assertNoPhoneLogged();
    }

    public function test_digit_2_closes_without_any_further_protocol(): void
    {
        $verification = $this->makeVerification();

        $this->gather($verification, '2')->assertOk();

        $this->assertSame(0, Notification::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('notification_type', 'incident.emergency_confirmed')
            ->count());
    }
}
