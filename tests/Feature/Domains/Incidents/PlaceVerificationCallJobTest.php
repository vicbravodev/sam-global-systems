<?php

namespace Tests\Feature\Domains\Incidents;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Actions\HandleVerificationCallAttemptFailure;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Jobs\EvaluateVerificationCallOutcomeJob;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\TenantChannelToggle;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\User;
use Database\Seeders\IncidentsMeterSeeder;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlaceVerificationCallJobTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        $this->teamId = User::factory()->create()->currentTeam->id;
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
        (new PlaceVerificationCallJob($verification->id))->handle(
            app(TwilioVoiceCaller::class),
            app(TenantConfigResolver::class),
            app(HandleVerificationCallAttemptFailure::class),
            app(RecordUsageEvent::class),
        );
    }

    public function test_places_the_call_with_gather_twiml_and_chains_the_safety_net(): void
    {
        $this->seed(IncidentsMeterSeeder::class);

        $channel = NotificationChannel::factory()->voice()->create(['team_id' => $this->teamId]);
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) use ($verification) {
            $mock->shouldReceive('createCall')
                ->once()
                ->withArgs(function (array $config, string $to, string $from, array $params) use ($verification) {
                    return $to === '+5215512345678'
                        && $from === $config['from']
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

        Queue::assertPushed(
            EvaluateVerificationCallOutcomeJob::class,
            fn (EvaluateVerificationCallOutcomeJob $job) => $job->verificationId === $verification->id,
        );
    }

    public function test_uses_the_platform_global_channel_when_the_tenant_has_none(): void
    {
        $global = NotificationChannel::factory()->voice()->create(['team_id' => null]);
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->once()->andReturn((object) ['sid' => 'CA-g', 'status' => 'queued']);
        });

        $this->runJob($verification);

        $this->assertSame($global->id, $verification->fresh()->notification_channel_id);
    }

    public function test_prefers_the_tenant_channel_over_the_global_one(): void
    {
        NotificationChannel::factory()->voice()->create(['team_id' => null]);
        $own = NotificationChannel::factory()->voice()->create(['team_id' => $this->teamId]);
        $verification = $this->makeVerification();

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->once()->andReturn((object) ['sid' => 'CA-t', 'status' => 'queued']);
        });

        $this->runJob($verification);

        $this->assertSame($own->id, $verification->fresh()->notification_channel_id);
    }

    public function test_global_voice_channel_disabled_by_the_tenant_never_serves_calls(): void
    {
        $global = NotificationChannel::factory()->voice()->create(['team_id' => null]);

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
    }

    public function test_placement_exception_chains_the_next_attempt(): void
    {
        NotificationChannel::factory()->voice()->create(['team_id' => $this->teamId]);
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
    }

    public function test_exhausted_attempts_escalate_the_incident_with_no_answer_outcome(): void
    {
        NotificationChannel::factory()->voice()->create(['team_id' => $this->teamId]);

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
    }

    public function test_no_ops_when_request_is_not_pending(): void
    {
        NotificationChannel::factory()->voice()->create(['team_id' => $this->teamId]);
        $verification = $this->makeVerification(['status' => CallVerificationStatus::Answered]);

        $this->mock(TwilioVoiceCaller::class, function ($mock) {
            $mock->shouldReceive('createCall')->never();
        });

        $this->runJob($verification);

        $this->assertSame(CallVerificationStatus::Answered, $verification->fresh()->status);
    }

    public function test_terminal_incident_cancels_the_call(): void
    {
        NotificationChannel::factory()->voice()->create(['team_id' => $this->teamId]);

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
    }
}
