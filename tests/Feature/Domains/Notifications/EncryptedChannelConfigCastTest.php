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
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
                'secret' => 'super-secret-value',
                'api_key' => 'provider-api-key',
                'unrelated' => 'plain-text',
            ],
        ]);

        $rawJson = (string) DB::table('notification_channels')
            ->where('id', $channel->id)
            ->value('config_json');

        $this->assertStringNotContainsString('super-secret-value', $rawJson);
        $this->assertStringNotContainsString('provider-api-key', $rawJson);
        $this->assertStringContainsString('plain-text', $rawJson);

        $rawDecoded = json_decode($rawJson, true);
        $this->assertArrayHasKey('__enc', $rawDecoded['secret']);
        $this->assertArrayHasKey('__enc', $rawDecoded['api_key']);

        $reloaded = NotificationChannel::query()->find($channel->id);
        $this->assertSame('super-secret-value', $reloaded->config_json['secret']);
        $this->assertSame('provider-api-key', $reloaded->config_json['api_key']);
        $this->assertSame('plain-text', $reloaded->config_json['unrelated']);
        $this->assertSame('https://example.com/hook', $reloaded->config_json['endpoint_url']);
    }

    public function test_round_trip_through_save_keeps_values_intact(): void
    {
        $team = Team::factory()->create();

        $channel = NotificationChannel::factory()->create([
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
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => null,
        ]);

        $this->assertNull($channel->fresh()->config_json);
    }

    public function test_unencodable_config_is_rejected_instead_of_wiping_the_stored_config(): void
    {
        // Regresión: json_encode devolvía false con UTF-8 inválido y el cast lo
        // guardaba tal cual; al releer, config_json quedaba en null y el canal
        // perdía en silencio toda su configuración (incluidos los secretos).
        $channel = NotificationChannel::factory()->create([
            'channel_type' => ChannelType::Webhook,
            'provider' => 'webhook',
            'config_json' => [
                'endpoint_url' => 'https://example.com/hook',
                'secret' => 'kept-secret',
            ],
        ]);

        try {
            $channel->config_json = [
                'endpoint_url' => 'https://example.com/hook',
                'label' => "\xB1\x31",
            ];
            $channel->save();
            $this->fail('Una config que no se puede codificar a JSON no debe guardarse.');
        } catch (\JsonException) {
            // esperado
        }

        $reloaded = NotificationChannel::query()->find($channel->id);
        $this->assertSame('kept-secret', $reloaded->config_json['secret']);
        $this->assertSame('https://example.com/hook', $reloaded->config_json['endpoint_url']);
    }

    public function test_sensitive_key_list_is_complete(): void
    {
        // Twilio credentials are platform env only (TWILIO_*): no channel
        // row ever carries them, so they are not part of this list.
        $expected = [
            'secret', 'webhook_secret',
            'api_key', 'api_secret', 'server_key', 'firebase_credentials',
            'slack_webhook_url',
        ];

        $this->assertSame($expected, EncryptedChannelConfigCast::SENSITIVE_KEYS);
    }
}
