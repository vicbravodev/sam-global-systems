<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
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
}
