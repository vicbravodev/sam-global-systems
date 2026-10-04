<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Access\Models\Role;
use App\Domains\Incidents\Actions\NotifyEscalationLevel;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Channels\WebPushMessenger;
use App\Domains\Notifications\Data\WebPushOutcome;
use App\Domains\Notifications\Data\WebPushTarget;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\PushSubscription;
use App\Domains\Notifications\Support\DeliveryEscalationGuard;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Database\Seeders\PlatformChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * El canal push ya estaba en la política crítica y en la escalera por
 * defecto; esto fija que, con un dispositivo suscrito, la escalera real
 * produce una entrega al de turno, que push no frena lo pagado y que sin
 * dispositivo (en ese team) el canal ni se intenta.
 */
class PushChannelRoutingTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    private User $onCall;

    /** @var list<list<WebPushTarget>> */
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
        $this->seed(NotificationMeterSeeder::class);
        $this->seed(PlatformChannelSeeder::class);
        Cache::flush();

        $owner = User::factory()->withVerifiedPhone('+5215510000001')->create();
        $this->team = $owner->currentTeam;
        $this->onCall = User::factory()->withVerifiedPhone('+5215510000003')->create();
        $this->team->members()->attach($this->onCall, ['role' => 'member']);
        Membership::query()->where('team_id', $this->team->id)->where('user_id', $this->onCall->id)
            ->update(['role_id' => Role::query()->where('code', 'monitorista')->firstOrFail()->id]);
        TenantScheduleProfile::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'shift_rules_json' => ['on_call' => [['user_id' => $this->onCall->id]]],
        ]);

        TenantEscalationConfig::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'steps_json' => [['delay_minutes' => 0, 'audience' => 'on_call', 'channels' => ['sms', 'push', 'web'], 'attempts' => 1, 'contacts' => []]],
        ]);

        $this->app->instance(TwilioMessenger::class, Mockery::mock(TwilioMessenger::class)->shouldIgnoreMissing());
        $this->app->instance(TwilioVoiceCaller::class, Mockery::mock(TwilioVoiceCaller::class)->shouldIgnoreMissing());

        $test = $this;
        $this->app->instance(WebPushMessenger::class, new class($test) extends WebPushMessenger
        {
            public function __construct(private readonly PushChannelRoutingTest $test) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(array $targets, string $payload, int $ttl, string $urgency): array
            {
                return $this->test->record($targets);
            }
        });
    }

    /**
     * @param  list<WebPushTarget>  $targets
     * @return list<WebPushOutcome>
     */
    public function record(array $targets): array
    {
        $this->pushed[] = $targets;

        return array_map(fn (WebPushTarget $t) => new WebPushOutcome($t->subscriptionId, true, false, 201, null), $targets);
    }

    private function notifyLevelZero(): void
    {
        $priority = IncidentPriority::query()->where('code', 'critical')->firstOrFail();
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id, 'incident_priority_id' => $priority->id]);

        $this->assertNoTenantLeak($this->team, fn () => app(NotifyEscalationLevel::class)->execute(
            incident: $incident,
            level: 0,
            eventKey: "push_routing:{$incident->id}:0",
            notificationType: 'incident.sla_breached',
            subject: 'Nadie lo ha atendido',
            body: 'SAM: nadie lo ha atendido.',
        ));
    }

    private function deliveries(ChannelType $type)
    {
        return NotificationDelivery::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereHas('channel', fn ($q) => $q->where('channel_type', $type->value));
    }

    public function test_the_escalation_ladder_reaches_the_on_call_device(): void
    {
        PushSubscription::factory()->forMember($this->onCall, $this->team)->create();

        $this->notifyLevelZero();

        $push = $this->deliveries(ChannelType::Push)->sole();

        $this->assertSame(DeliveryStatus::Delivered, $push->status);
        $this->assertCount(1, $this->pushed);
        $this->assertSystemLogged('notifications.push.sent');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_delivered_push_does_not_count_as_reaching_the_recipient(): void
    {
        PushSubscription::factory()->forMember($this->onCall, $this->team)->create();

        $this->notifyLevelZero();

        $sms = $this->deliveries(ChannelType::Sms)->first();
        $this->assertNotNull($sms, 'La escalera por defecto debe incluir SMS en el nivel 0 de un crítico');

        $verdict = DeliveryEscalationGuard::explain($sms);

        $this->assertNotSame('recipient_reached', $verdict['reason']);
    }

    public function test_a_user_without_a_device_is_skipped_not_failed_and_gets_no_push_fallback(): void
    {
        $this->notifyLevelZero();

        $push = $this->deliveries(ChannelType::Push)->get();
        $this->assertCount(1, $push);
        $this->assertSame(DeliveryStatus::Skipped, $push->first()->status);
        $this->assertSame([], $this->pushed);
        $this->assertSystemLogged('notifications.delivery.skipped', fn (array $c) => $c['reason'] === 'no_push_device' && $c['input']['channel_type'] === 'push');
        $this->assertSame([], array_filter(
            $this->systemLogEntries('notifications.fallback.requested'),
            fn (array $e) => ($e['context']['input']['channel_type'] ?? null) === 'push',
        ));
        $this->assertSame(0, $this->deliveries(ChannelType::Push)->where('status', DeliveryStatus::Failed->value)->count());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_device_subscribed_in_another_team_does_not_count(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        PushSubscription::factory()->forMember($this->onCall, $otherTeam)->create();

        $this->notifyLevelZero();

        $this->assertSame(DeliveryStatus::Skipped, $this->deliveries(ChannelType::Push)->sole()->status);
        $this->assertSame([], $this->pushed);
    }
}
