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
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class RecipientChannelAddressTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

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
    }

    public function test_team_fanout_omits_unverified_user_phone(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550144', 'phone_verified_at' => null]);
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
        $this->assertNull($descriptors[0]->phone);
    }

    public function test_team_fanout_includes_verified_user_phone(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550144', 'phone_verified_at' => now()]);
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

        $explain = app(ResolveRecipients::class)->explain($notification);
        $this->assertEquals($descriptors, $explain['descriptors']);
        $this->assertSame('explicit', $explain['source']);
        $this->assertSame(2, $explain['candidates_count']);
        $this->assertSame([], $explain['dropped_count_by_reason']);
    }

    public function test_explain_counts_explicit_entries_dropped_by_reason(): void
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
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                    ['recipient_type' => RecipientType::ExternalContact->value, 'name' => 'Sin dirección'],
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => ''],
                    'not-an-array',
                ],
            ],
        ]);

        $explain = app(ResolveRecipients::class)->explain($notification);

        $this->assertCount(1, $explain['descriptors']);
        $this->assertSame('explicit', $explain['source']);
        $this->assertSame(4, $explain['candidates_count']);
        $this->assertSame(2, $explain['dropped_count_by_reason']['no_address']);
        $this->assertSame(1, $explain['dropped_count_by_reason']['not_an_array']);
        $this->assertEquals($explain['descriptors'], app(ResolveRecipients::class)->execute($notification));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_explain_counts_team_members_without_email(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $mute = User::factory()->create();
        $team->members()->attach($mute, ['role' => 'member']);
        $mute->forceFill(['email' => ''])->saveQuietly();

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [],
        ]);

        $explain = app(ResolveRecipients::class)->explain($notification);

        $this->assertSame('team_members', $explain['source']);
        $this->assertSame(2, $explain['candidates_count']);
        $this->assertCount(1, $explain['descriptors']);
        $this->assertSame(['no_email' => 1], $explain['dropped_count_by_reason']);
    }

    public function test_push_address_is_the_user_id_for_user_recipients(): void
    {
        $recipient = NotificationRecipient::factory()->make([
            'recipient_type' => RecipientType::User,
            'recipient_reference_id' => '17',
            'address' => 'ana@example.com',
        ]);

        $this->assertSame('17', $recipient->addressForChannel(ChannelType::Push));
    }

    public function test_push_has_no_address_for_external_contacts(): void
    {
        $recipient = NotificationRecipient::factory()->make([
            'recipient_type' => RecipientType::ExternalContact,
            'recipient_reference_id' => null,
            'address' => 'externo@example.com',
        ]);

        $this->assertNull($recipient->addressForChannel(ChannelType::Push));
    }
}
