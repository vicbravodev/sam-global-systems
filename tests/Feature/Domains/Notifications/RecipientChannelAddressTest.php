<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\ResolveRecipients;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipientChannelAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_address_for_channel_picks_phone_for_telephony_and_email_for_mail(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $recipient = NotificationRecipient::factory()->create([
            'team_id' => $user->currentTeam->id,
            'address' => 'ops@example.com',
            'email' => 'ops@example.com',
            'phone' => '+5215555550100',
        ]);

        $this->assertSame('ops@example.com', $recipient->addressForChannel(ChannelType::Email));
        $this->assertSame('+5215555550100', $recipient->addressForChannel(ChannelType::Sms));
        $this->assertSame('+5215555550100', $recipient->addressForChannel(ChannelType::Voice));
        $this->assertSame('+5215555550100', $recipient->addressForChannel(ChannelType::Whatsapp));
    }

    public function test_address_for_telephony_is_null_when_phone_missing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $recipient = NotificationRecipient::factory()->create([
            'team_id' => $user->currentTeam->id,
            'address' => 'ops@example.com',
            'email' => 'ops@example.com',
            'phone' => null,
        ]);

        $this->assertNull($recipient->addressForChannel(ChannelType::Sms));
        $this->assertSame('ops@example.com', $recipient->addressForChannel(ChannelType::Email));
    }

    public function test_non_telephony_channels_fall_back_to_address(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $recipient = NotificationRecipient::factory()->create([
            'team_id' => $user->currentTeam->id,
            'address' => 'someone@example.com',
            'email' => null,
            'phone' => null,
        ]);

        $this->assertSame('someone@example.com', $recipient->addressForChannel(ChannelType::Web));
        $this->assertSame('someone@example.com', $recipient->addressForChannel(ChannelType::Push));
    }

    public function test_team_fanout_includes_user_phone(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550144']);
        $team = $user->currentTeam;
        $this->actingAs($user);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [],
        ]);

        $descriptors = app(ResolveRecipients::class)->execute($notification);

        $this->assertCount(1, $descriptors);
        $this->assertSame($user->email, $descriptors[0]->email);
        $this->assertSame('+5215555550144', $descriptors[0]->phone);
    }

    public function test_explicit_contact_classifies_phone_vs_email(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => '+5215555550155'],
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        $descriptors = app(ResolveRecipients::class)->execute($notification);

        $this->assertSame('+5215555550155', $descriptors[0]->phone);
        $this->assertNull($descriptors[0]->email);
        $this->assertSame('ops@example.com', $descriptors[1]->email);
        $this->assertNull($descriptors[1]->phone);
    }
}
