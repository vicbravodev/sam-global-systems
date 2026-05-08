<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Support\EncryptedChannelConfigCast;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EncryptedChannelConfigCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_keys_are_encrypted_at_rest_and_decrypted_on_read(): void
    {
        $team = Team::factory()->create();

        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
                'secret' => 'super-secret-value',
                'auth_token' => 'twilio-token',
                'unrelated' => 'plain-text',
            ],
        ]);

        $rawJson = (string) DB::table('notification_channels')
            ->where('id', $channel->id)
            ->value('config_json');

        $this->assertStringNotContainsString('super-secret-value', $rawJson);
        $this->assertStringNotContainsString('twilio-token', $rawJson);
        $this->assertStringContainsString('plain-text', $rawJson);

        $rawDecoded = json_decode($rawJson, true);
        $this->assertArrayHasKey('__enc', $rawDecoded['secret']);
        $this->assertArrayHasKey('__enc', $rawDecoded['auth_token']);

        $reloaded = NotificationChannel::query()->find($channel->id);
        $this->assertSame('super-secret-value', $reloaded->config_json['secret']);
        $this->assertSame('twilio-token', $reloaded->config_json['auth_token']);
        $this->assertSame('plain-text', $reloaded->config_json['unrelated']);
        $this->assertSame('https://example.com/hook', $reloaded->config_json['endpoint_url']);
    }

    public function test_round_trip_through_save_keeps_values_intact(): void
    {
        $team = Team::factory()->create();

        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
                'secret' => 'first',
            ],
        ]);

        $reloaded = NotificationChannel::query()->find($channel->id);
        $config = $reloaded->config_json;
        $config['endpoint_url'] = 'https://example.com/hook2';
        $reloaded->config_json = $config;
        $reloaded->save();

        $reloaded = NotificationChannel::query()->find($channel->id);
        $this->assertSame('first', $reloaded->config_json['secret']);
        $this->assertSame('https://example.com/hook2', $reloaded->config_json['endpoint_url']);
    }

    public function test_null_config_round_trips_as_null(): void
    {
        $team = Team::factory()->create();

        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => null,
        ]);

        $this->assertNull($channel->fresh()->config_json);
    }

    public function test_sensitive_key_list_is_complete(): void
    {
        $expected = [
            'secret', 'webhook_secret', 'auth_token', 'account_sid',
            'api_key', 'api_secret', 'server_key', 'firebase_credentials',
            'slack_webhook_url', 'twilio_auth_token', 'twilio_account_sid',
        ];

        $this->assertSame($expected, EncryptedChannelConfigCast::SENSITIVE_KEYS);
    }
}
