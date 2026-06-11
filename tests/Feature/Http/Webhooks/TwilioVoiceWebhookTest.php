<?php

namespace Tests\Feature\Http\Webhooks;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Roadmap V2-A3: DTMF gather (1 = real, 2 = falsa alarma) and call status
 * callbacks for the operator verification call.
 */
class TwilioVoiceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH_TOKEN = 'voice-tok-789';

    private Team $team;

    private NotificationChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        $this->team = User::factory()->create()->currentTeam;

        $this->channel = NotificationChannel::factory()->voice()->create([
            'team_id' => $this->team->id,
            'config_json' => [
                'twilio_account_sid' => 'AC-voice',
                'twilio_auth_token' => self::AUTH_TOKEN,
                'from' => '+15005550006',
            ],
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

    public function test_digit_1_acknowledges_the_incident_as_real_emergency(): void
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

        $incident = Incident::withoutGlobalScopes()->find($verification->incident_id);
        $this->assertNotNull($incident->acknowledged_at);

        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', 'verification_call')
            ->count());

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('action', 'incident.call_verification.confirmed_real')
            ->count());
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
    }

    public function test_invalid_digit_replays_the_prompt(): void
    {
        $verification = $this->makeVerification();

        $response = $this->gather($verification, '9');

        $response->assertOk();
        $this->assertStringContainsString('<Gather', $response->getContent());
        $this->assertNull($verification->fresh()->outcome);
    }

    public function test_invalid_signature_is_rejected_with_403(): void
    {
        $verification = $this->makeVerification();

        $this->gather($verification, '1', authToken: null)->assertForbidden();
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
    }
}
