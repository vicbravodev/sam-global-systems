<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Jobs\EvaluateVerificationCallOutcomeJob;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\TenantChannelToggle;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\User;
use Database\Seeders\IncidentsMeterSeeder;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class PlaceVerificationCallJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        $this->teamId = User::factory()->create()->currentTeam->id;

        // Twilio credentials are platform env only (TWILIO_*).
        config()->set('services.twilio.account_sid', 'AC_PLATFORM');
        config()->set('services.twilio.auth_token', 'tok_platform');
    }

    private function makeVerification(array $attributes = []): IncidentCallVerification
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->teamId]);

        return IncidentCallVerification::factory()->create(array_merge([
            'team_id' => $this->teamId,
            'incident_id' => $incident->id,
            'phone' => '+5215512345678',
        ], $attributes));
    }

    private function runJob(IncidentCallVerification $verification): void
    {
        app()->call([new PlaceVerificationCallJob($verification->id), 'handle']);
    }

    public function test_places_the_call_with_gather_twiml_and_chains_the_safety_net(): void
    {
        $this->seed(IncidentsMeterSeeder::class);

        $channel = NotificationChannel::factory()->voice()->create();
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) use ($verification) {
            $mock->shouldReceive('createCall')
                ->once()
                ->withArgs(function (string $to, string $from, array $params) use ($verification) {
                    return $to === '+5215512345678'
                        && $from === '+15005550006'
                        && str_contains($params['twiml'], '<Gather')
                        && str_contains($params['twiml'], 'Presione 1')
                        && str_contains($params['twiml'], "voice/{$verification->id}/gather")
                        && str_contains($params['statusCallback'], "voice/{$verification->id}/status");
                })
                ->andReturn((object) ['sid' => 'CA-test-1', 'status' => 'queued']);
        });

        $this->runJob($verification);

        $fresh = $verification->fresh();
        $this->assertSame(CallVerificationStatus::Calling, $fresh->status);
        $this->assertSame('CA-test-1', $fresh->call_sid);
        $this->assertSame($channel->id, $fresh->notification_channel_id);
        $this->assertNotNull($fresh->placed_at);

        $this->assertSame(1, UsageEvent::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->where('event_key', "voice_call:{$verification->id}")
            ->count());

        // The call's Twilio cost is tracked for cost-plus billing.
        $charge = MessagingCharge::withoutGlobalScopes()->where('provider_sid', 'CA-test-1')->sole();
        $this->assertSame($this->teamId, $charge->team_id);
        $this->assertSame(MessagingChargeSource::VerificationCall, $charge->source_type);
        $this->assertSame($verification->id, $charge->source_id);

        Queue::assertPushed(
            EvaluateVerificationCallOutcomeJob::class,
            fn (EvaluateVerificationCallOutcomeJob $job) => $job->verificationId === $verification->id,
        );

        $context = $this->assertSystemLogged('incidents.call_verification.placed', fn (array $c) => $c['input'] === [
            'verification_id' => $verification->id,
            'incident_id' => $verification->incident_id,
            'attempt' => 1,
        ]);
        $calc = $context['calc'];
        $this->assertSame(30, $calc['min_retry_delay_seconds']);
        $this->assertSame(90, $calc['configured_retry_delay_seconds']);
        $this->assertSame(max($calc['min_retry_delay_seconds'], $calc['configured_retry_delay_seconds']), $calc['retry_delay_seconds']);
        $this->assertArrayHasKey('ring_timeout_seconds', $calc);
        $this->assertArrayHasKey('duration_ms', $context);
        $this->assertSame([
            'call_sid' => 'CA-test-1',
            'provider_status' => 'queued',
            'channel_id' => $channel->id,
            'safety_net_requested' => true,
        ], $context['result']);

        // El delay del job empujado es exactamente el registrado.
        Queue::assertPushed(
            EvaluateVerificationCallOutcomeJob::class,
            fn (EvaluateVerificationCallOutcomeJob $job) => (int) round(Carbon::now()->diffInSeconds($job->delay, true)) === $calc['retry_delay_seconds'],
        );

        $this->assertStringNotContainsString('5215512345678', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_safety_net_waits_until_the_retry_delay_is_due(): void
    {
        $verification = $this->makeVerification([
            'status' => CallVerificationStatus::Calling,
            'placed_at' => now()->subSeconds(10),
        ]);

        app()->call([new EvaluateVerificationCallOutcomeJob($verification->id), 'handle']);

        $this->assertSame(CallVerificationStatus::Calling, $verification->fresh()->status);

        $context = $this->assertSystemLogged('incidents.call_verification.safety_net', fn (array $c) => $c['reason'] === 'not_due_yet');
        $this->assertSame(['verification_id' => $verification->id, 'incident_id' => $verification->incident_id, 'attempt' => 1], $context['input']);
        $this->assertSame(90, $context['calc']['retry_delay_seconds']);
        $this->assertSame(
            Carbon::parse($context['calc']['placed_at'])->addSeconds($context['calc']['retry_delay_seconds'])->toIso8601String(),
            $context['calc']['due_at'],
        );
        $this->assertSystemNotLogged('incidents.call_verification.attempt_failed');
    }

    public function test_the_safety_net_fails_an_overdue_attempt_without_callback(): void
    {
        $verification = $this->makeVerification([
            'status' => CallVerificationStatus::Calling,
            'placed_at' => now()->subSeconds(120),
        ]);

        app()->call([new EvaluateVerificationCallOutcomeJob($verification->id), 'handle']);

        $this->assertSame(CallVerificationStatus::NoAnswer, $verification->fresh()->status);

        $this->assertSystemLogged('incidents.call_verification.safety_net', fn (array $c) => $c['outcome'] === 'ok'
            && $c['result'] === ['failure_code' => 'timeout_without_callback']
            && $c['calc']['retry_delay_seconds'] === 90);
        $this->assertSystemLogged('incidents.call_verification.attempt_failed', fn (array $c) => $c['calc']['failure_code'] === 'timeout_without_callback');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_safety_net_ignores_an_attempt_that_is_no_longer_in_flight(): void
    {
        $verification = $this->makeVerification(['status' => CallVerificationStatus::Answered]);

        app()->call([new EvaluateVerificationCallOutcomeJob($verification->id), 'handle']);

        $entry = collect($this->systemLogEntries('incidents.call_verification.skipped'))->sole();
        $this->assertSame('debug', $entry['level']);
        $this->assertSame('not_in_flight', $entry['context']['reason']);
        $this->assertSame(['verification_id' => $verification->id], $entry['context']['input']);
    }

    public function test_uses_the_platform_voice_channel(): void
    {
        $global = NotificationChannel::factory()->voice()->create();
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->once()->andReturn((object) ['sid' => 'CA-g', 'status' => 'queued']);
        });

        $this->runJob($verification);

        $this->assertSame($global->id, $verification->fresh()->notification_channel_id);
    }

    public function test_uses_the_platform_env_sender_when_channel_has_no_config(): void
    {
        config()->set('services.twilio.voice_from', '+15550003333');

        NotificationChannel::factory()->voice()->create([
            'config_json' => null,
        ]);
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')
                ->once()
                ->withArgs(function (string $to, string $from) {
                    return $from === '+15550003333';
                })
                ->andReturn((object) ['sid' => 'CA-env', 'status' => 'queued']);
        });

        $this->runJob($verification);

        $this->assertSame(CallVerificationStatus::Calling, $verification->fresh()->status);
    }

    public function test_does_not_call_without_platform_twilio_credentials(): void
    {
        config()->set('services.twilio.account_sid', null);
        config()->set('services.twilio.auth_token', null);

        // Legacy per-channel credentials must never be used.
        NotificationChannel::factory()->voice()->create([
            'config_json' => ['from' => '+15005550006', 'twilio_account_sid' => 'AC_TENANT', 'twilio_auth_token' => 'tok_tenant'],
        ]);
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->never();
        });

        $this->runJob($verification);

        $this->assertSame('voice_channel_unavailable', $verification->fresh()->metadata_json['failure_reason']);

        $this->assertSystemLogged('incidents.call_verification.skipped', fn (array $c) => $c['reason'] === 'no_voice_channel'
            && $c['calc'] === ['channel_present' => true, 'from_present' => true, 'credentials_present' => false]);
        $this->assertSystemLogged('incidents.call_verification.unverifiable_escalated', fn (array $c) => $c['input']['unverifiable_code'] === 'voice_channel_unavailable'
            && $c['result']['escalated_now'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_global_voice_channel_disabled_by_the_tenant_never_serves_calls(): void
    {
        $global = NotificationChannel::factory()->voice()->create();

        TenantChannelToggle::factory()->disabled()->create([
            'team_id' => $this->teamId,
            'notification_channel_id' => $global->id,
        ]);

        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->never();
        });

        $this->runJob($verification);

        $this->assertSame(CallVerificationStatus::Failed, $verification->fresh()->status);
    }

    public function test_fails_without_consuming_attempts_when_no_voice_channel_exists(): void
    {
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->never();
        });

        $this->runJob($verification);

        $fresh = $verification->fresh();
        $this->assertSame(CallVerificationStatus::Failed, $fresh->status);
        $this->assertSame('voice_channel_unavailable', $fresh->metadata_json['failure_reason']);
        $this->assertSame(1, IncidentCallVerification::withoutGlobalScopes()->count());

        $this->assertSystemLogged('incidents.call_verification.skipped', fn (array $c) => $c['reason'] === 'no_voice_channel'
            && $c['calc']['channel_present'] === false);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_placement_exception_chains_the_next_attempt(): void
    {
        NotificationChannel::factory()->voice()->create();
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->once()->andThrow(new \RuntimeException('twilio down'));
        });

        $this->runJob($verification);

        $this->assertSame(CallVerificationStatus::NoAnswer, $verification->fresh()->status);

        $next = IncidentCallVerification::withoutGlobalScopes()
            ->where('incident_id', $verification->incident_id)
            ->where('attempt', 2)
            ->sole();

        Queue::assertPushed(
            PlaceVerificationCallJob::class,
            fn (PlaceVerificationCallJob $job) => $job->verificationId === $next->id,
        );

        $this->assertSystemLogged('incidents.call_verification.placement_failed', fn (array $c) => $c['reason'] === 'provider_error'
            && $c['result']['error_class'] === 'RuntimeException');
        $this->assertSystemLogged('incidents.call_verification.attempt_failed', fn (array $c) => $c['outcome'] === 'ok'
            && $c['calc']['failure_code'] === 'placement_failed'
            && $c['calc']['call_status'] === null
            && $c['result'] === ['next' => 'next_attempt', 'next_attempt' => 2, 'next_attempt_created' => true]);
        $this->assertStringNotContainsString('twilio down', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_exhausted_attempts_escalate_the_incident_with_no_answer_outcome(): void
    {
        NotificationChannel::factory()->voice()->create();

        // Last attempt of the default budget (3).
        $verification = $this->makeVerification(['attempt' => 3]);

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->once()->andThrow(new \RuntimeException('twilio down'));
        });

        $this->runJob($verification);

        $fresh = $verification->fresh();
        $this->assertSame(CallVerificationStatus::NoAnswer, $fresh->status);
        $this->assertSame(CallVerificationOutcome::NoAnswer, $fresh->outcome);

        $incident = Incident::withoutGlobalScopes()->with('status')->find($verification->incident_id);
        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->status?->code);

        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', 'verification_call')
            ->count());

        Queue::assertNotPushed(PlaceVerificationCallJob::class);

        $context = $this->assertSystemLogged('incidents.call_verification.attempt_failed', fn (array $c) => $c['result']['next'] === 'exhausted_escalated');
        $this->assertSame(CallVerificationOutcome::NoAnswer->value, $context['result']['outcome']);
        $this->assertSame(3, $context['calc']['budget']);
        $this->assertSame(min($context['calc']['max_attempts_cap'], max($context['calc']['configured_attempts'], $context['calc']['candidates_count'])), $context['calc']['budget']);
        $this->assertStringNotContainsString('twilio down', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_no_ops_when_request_is_not_pending(): void
    {
        NotificationChannel::factory()->voice()->create();
        $verification = $this->makeVerification(['status' => CallVerificationStatus::Answered]);

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->never();
        });

        $this->runJob($verification);

        $this->assertSame(CallVerificationStatus::Answered, $verification->fresh()->status);

        $entry = collect($this->systemLogEntries('incidents.call_verification.skipped'))->sole();
        $this->assertSame('debug', $entry['level']);
        $this->assertSame('not_pending', $entry['context']['reason']);
        $this->assertSame(['verification_id' => $verification->id], $entry['context']['input']);
    }

    public function test_terminal_incident_cancels_the_call(): void
    {
        NotificationChannel::factory()->voice()->create();

        $incident = Incident::factory()->closed()->create(['team_id' => $this->teamId]);
        $verification = IncidentCallVerification::factory()->create([
            'team_id' => $this->teamId,
            'incident_id' => $incident->id,
        ]);

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->never();
        });

        $this->runJob($verification);

        $fresh = $verification->fresh();
        $this->assertSame(CallVerificationStatus::Failed, $fresh->status);
        $this->assertSame('incident_terminal', $fresh->metadata_json['failure_reason']);

        $this->assertSystemLogged('incidents.call_verification.closed', fn (array $c) => $c['reason'] === 'incident_terminal'
            && $c['input'] === ['verification_id' => $verification->id, 'incident_id' => $incident->id, 'attempt' => $verification->attempt]);
        $this->assertNoSensitiveDataLogged();
    }
}
