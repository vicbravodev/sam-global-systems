<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Tenant-facing delivery detail of a notification: who was contacted, by
 * which channel, what the provider reported and why it failed. The provider
 * cost is never exposed here.
 */
class NotificationDeliveryDetailPageTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_detail_page_shows_per_channel_feedback_without_cost(): void
    {
        $notification = Notification::factory()->create([
            'team_id' => $this->team->id,
            'status' => NotificationStatus::PartiallySent,
            'subject' => 'Pánico en Camión 7',
        ]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $this->team->id,
            'name' => 'Ana Operadora',
            'phone' => '+5215512345678',
        ]);

        $sms = $this->delivery($notification, $recipient, ChannelType::Sms, [
            'status' => DeliveryStatus::Failed,
            'provider_message_id' => 'SM_DETAIL',
            'provider_status' => 'undelivered',
            'provider_error_code' => '30005',
            'failed_at' => now(),
            'payload_json' => ['address' => '+5215512345678', 'subject' => null, 'body' => 'x'],
        ]);
        MessagingCharge::factory()->forDelivery($sms)->create([
            'price_micros' => 7_900,
            'events_json' => [
                ['status' => 'queued', 'error_code' => null, 'at' => now()->toIso8601String(), 'source' => 'api'],
                ['status' => 'undelivered', 'error_code' => '30005', 'at' => now()->toIso8601String(), 'source' => 'callback'],
            ],
        ]);

        $this->delivery($notification, $recipient, ChannelType::Voice, [
            'status' => DeliveryStatus::Delivered,
            'provider_message_id' => 'CA_DETAIL',
            'provider_status' => 'completed',
            'answered_at' => now(),
            'delivered_at' => now(),
            'call_duration_seconds' => 42,
        ]);

        $this->delivery($notification, $recipient, ChannelType::Email, [
            'status' => DeliveryStatus::Skipped,
            'attempt_number' => 0,
            'error_message' => 'no email address (missing phone/email) for recipient',
        ]);

        $response = $this->actingAs($this->user)->get(route('notifications.show', [
            'current_team' => $this->team->slug,
            'notification' => $notification->id,
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('notifications/show')
            ->where('notification.id', $notification->id)
            ->has('deliveries', 3)
            ->where('deliveries.0.channel.type', 'sms')
            ->where('deliveries.0.statusLabel', 'No entregado')
            ->where('deliveries.0.reason', 'El número de destino no existe o ya no está activo.')
            ->where('deliveries.0.tone', 'danger')
            ->where('deliveries.0.address', '+52•••••••5678')
            ->has('deliveries.0.events', 2)
            ->where('deliveries.0.events.1.label', 'No entregado')
            ->where('deliveries.1.channel.type', 'voice')
            ->where('deliveries.1.statusLabel', 'Llamada contestada · 0:42')
            // Sent in the same dispatch round as the SMS: listed after a
            // failure, but not a fallback of it (UI audit P1-7).
            ->where('deliveries.1.isFallback', false)
            ->where('deliveries.2.statusLabel', 'Omitido')
            ->where('deliveries.2.reason', 'El destinatario no tiene dato de contacto para este canal.')
            ->missing('deliveries.0.priceMicros'));

        // Provider cost is internal: never reaches the tenant.
        $deliveries = json_encode($response->viewData('page')['props']['deliveries']);
        $this->assertStringNotContainsString('7900', $deliveries);
        $this->assertStringNotContainsString('price', $deliveries);
    }

    public function test_fallback_label_follows_the_recorded_fallback_link(): void
    {
        [$notification, $recipient] = $this->notificationWithRecipient();

        $sms = $this->delivery($notification, $recipient, ChannelType::Sms, [
            'status' => DeliveryStatus::Failed,
            'failed_at' => now(),
        ]);
        $this->delivery($notification, $recipient, ChannelType::Email, [
            'status' => DeliveryStatus::Delivered,
            'fallback_from_delivery_id' => $sms->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('notifications.show', ['current_team' => $this->team->slug, 'notification' => $notification->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveries.0.isFallback', false)
                ->where('deliveries.1.isFallback', true)
                ->where('deliveries.1.fallbackFromChannel', 'SMS'));
    }

    public function test_legacy_fallback_is_detected_by_timing_not_by_list_order(): void
    {
        [$notification, $recipient] = $this->notificationWithRecipient();
        $dispatchedAt = now()->subMinutes(10);

        // Dispatch round: SMS fails, WhatsApp and web go out in the same second.
        $this->delivery($notification, $recipient, ChannelType::Sms, [
            'status' => DeliveryStatus::Failed,
            'created_at' => $dispatchedAt,
            'failed_at' => $dispatchedAt->copy()->addMinute(),
        ]);
        $this->delivery($notification, $recipient, ChannelType::Web, [
            'status' => DeliveryStatus::Delivered,
            'created_at' => $dispatchedAt,
        ]);
        // Written two minutes later, after the SMS failure: the real fallback.
        $this->delivery($notification, $recipient, ChannelType::Email, [
            'status' => DeliveryStatus::Delivered,
            'created_at' => $dispatchedAt->copy()->addMinutes(2),
        ]);

        $this->actingAs($this->user)
            ->get(route('notifications.show', ['current_team' => $this->team->slug, 'notification' => $notification->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveries.1.channel.type', 'web')
                ->where('deliveries.1.isFallback', false)
                ->where('deliveries.2.channel.type', 'email')
                ->where('deliveries.2.isFallback', true)
                ->where('deliveries.2.fallbackFromChannel', 'SMS'));
    }

    public function test_web_channel_target_is_the_inbox_not_an_email(): void
    {
        [$notification, $recipient] = $this->notificationWithRecipient();
        $recipient->update(['address' => 'ana@example.com', 'email' => 'ana@example.com']);

        $this->delivery($notification, $recipient, ChannelType::Web, ['status' => DeliveryStatus::Delivered]);

        $response = $this->actingAs($this->user)
            ->get(route('notifications.show', ['current_team' => $this->team->slug, 'notification' => $notification->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('deliveries.0.channel.type', 'web')
            ->where('deliveries.0.address', 'Bandeja web de SAM'));
    }

    public function test_row_status_is_honest_about_what_the_deliveries_did(): void
    {
        $allFailed = Notification::factory()->sent()->create(['team_id' => $this->team->id, 'subject' => 'all-failed']);
        $allFailedRecipient = NotificationRecipient::factory()->create(['notification_id' => $allFailed->id, 'team_id' => $this->team->id]);
        $this->delivery($allFailed, $allFailedRecipient, ChannelType::Sms, ['status' => DeliveryStatus::Failed]);
        $this->delivery($allFailed, $allFailedRecipient, ChannelType::Email, ['status' => DeliveryStatus::Bounced]);

        $partial = Notification::factory()->sent()->create(['team_id' => $this->team->id, 'subject' => 'partial']);
        $partialRecipient = NotificationRecipient::factory()->create(['notification_id' => $partial->id, 'team_id' => $this->team->id]);
        $this->delivery($partial, $partialRecipient, ChannelType::Sms, ['status' => DeliveryStatus::Failed]);
        $this->delivery($partial, $partialRecipient, ChannelType::Email, ['status' => DeliveryStatus::Delivered]);

        $inTransit = Notification::factory()->sent()->create(['team_id' => $this->team->id, 'subject' => 'in-transit']);
        $inTransitRecipient = NotificationRecipient::factory()->create(['notification_id' => $inTransit->id, 'team_id' => $this->team->id]);
        $this->delivery($inTransit, $inTransitRecipient, ChannelType::Sms, ['status' => DeliveryStatus::Sent]);

        $response = $this->actingAs($this->user)
            ->get(route('notifications.index', ['current_team' => $this->team->slug]));

        $rows = collect($response->viewData('page')['props']['notifications'])->keyBy('subject');

        $this->assertSame('Sin entregar', $rows['all-failed']['statusLabel']);
        $this->assertSame('critical', $rows['all-failed']['statusTone']);
        $this->assertSame('Parcial 1/2', $rows['partial']['statusLabel']);
        $this->assertSame('Sin confirmar entrega', $rows['in-transit']['statusLabel']);
    }

    public function test_list_summarizes_deliveries_and_filters_failures(): void
    {
        $failing = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $failing->id, 'team_id' => $this->team->id]);
        $this->delivery($failing, $recipient, ChannelType::Sms, ['status' => DeliveryStatus::Failed]);
        $this->delivery($failing, $recipient, ChannelType::Email, ['status' => DeliveryStatus::Delivered]);

        $clean = Notification::factory()->create(['team_id' => $this->team->id]);
        $cleanRecipient = NotificationRecipient::factory()->create(['notification_id' => $clean->id, 'team_id' => $this->team->id]);
        $this->delivery($clean, $cleanRecipient, ChannelType::Email, ['status' => DeliveryStatus::Delivered]);

        $this->actingAs($this->user)
            ->get(route('notifications.index', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('notifications/index')
                ->where('notifications.1.id', $failing->id)
                ->where('notifications.1.deliverySummary', ['attempted' => 2, 'delivered' => 1, 'failed' => 1])
                ->where('notifications.1.detailUrl', route('notifications.show', ['current_team' => $this->team->slug, 'notification' => $failing->id])));

        $this->actingAs($this->user)
            ->get(route('notifications.index', ['current_team' => $this->team->slug, 'failures' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications', 1)
                ->where('notifications.0.id', $failing->id)
                ->where('filters.failures', true));
    }

    public function test_another_tenants_notification_is_not_visible(): void
    {
        $other = Team::factory()->create();
        $foreign = Notification::factory()->create(['team_id' => $other->id]);

        $this->actingAs($this->user)
            ->get(route('notifications.show', ['current_team' => $this->team->slug, 'notification' => $foreign->id]))
            ->assertNotFound();
    }

    public function test_detail_page_never_reads_or_writes_another_tenant(): void
    {
        $other = Team::factory()->create();
        $foreign = Notification::factory()->create(['team_id' => $other->id]);
        $foreignRecipient = NotificationRecipient::factory()->create(['notification_id' => $foreign->id, 'team_id' => $other->id]);
        $foreignDelivery = $this->delivery($foreign, $foreignRecipient, ChannelType::Sms, ['provider_message_id' => 'SM_FOREIGN', 'status' => DeliveryStatus::Queued]);
        MessagingCharge::factory()->forDelivery($foreignDelivery)->create();

        $mine = Notification::factory()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create(['notification_id' => $mine->id, 'team_id' => $this->team->id]);
        $this->delivery($mine, $recipient, ChannelType::Sms, ['provider_message_id' => 'SM_MINE', 'status' => DeliveryStatus::Queued]);

        $response = $this->assertNoTenantLeak($this->team, fn () => $this->actingAs($this->user)->get(route('notifications.show', [
            'current_team' => $this->team->slug,
            'notification' => $mine->id,
        ])));

        $response->assertOk();
        $this->assertStringNotContainsString('SM_FOREIGN', $response->getContent());
        $response->assertInertia(fn (Assert $page) => $page->has('deliveries', 1));
    }

    /**
     * @return array{0: Notification, 1: NotificationRecipient}
     */
    private function notificationWithRecipient(): array
    {
        $notification = Notification::factory()->sent()->create(['team_id' => $this->team->id]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id,
            'team_id' => $this->team->id,
            'name' => 'Ana Operadora',
            'phone' => '+5215512345678',
        ]);

        return [$notification, $recipient];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function delivery(Notification $notification, NotificationRecipient $recipient, ChannelType $type, array $attributes): NotificationDelivery
    {
        $channel = NotificationChannel::query()->where('channel_type', $type)->first()
            ?? NotificationChannel::factory()->create(['channel_type' => $type, 'provider' => $type->value]);

        return NotificationDelivery::factory()->create([
            'notification_id' => $notification->id,
            'recipient_id' => $recipient->id,
            'channel_id' => $channel->id,
            'team_id' => $notification->team_id,
            ...$attributes,
        ]);
    }
}
