<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use Database\Seeders\PlatformChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformChannelSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_platform_channels_sam_operates(): void
    {
        $this->seed(PlatformChannelSeeder::class);

        foreach (['email', 'web', 'sms', 'whatsapp', 'voice'] as $type) {
            $this->assertTrue(
                NotificationChannel::query()
                    ->whereNull('team_id')
                    ->where('channel_type', ChannelType::from($type))
                    ->where('is_active', true)
                    ->exists(),
                "Missing platform channel for [{$type}]",
            );
        }
    }

    public function test_is_idempotent(): void
    {
        $this->seed(PlatformChannelSeeder::class);
        $this->seed(PlatformChannelSeeder::class);

        $this->assertSame(5, NotificationChannel::query()->whereNull('team_id')->count());
    }

    public function test_does_not_overwrite_an_existing_platform_channel(): void
    {
        NotificationChannel::factory()->sms()->create([
            'team_id' => null,
            'code' => 'sam_sms',
            'name' => 'SMS custom',
            'config_json' => ['from' => '+15551112222'],
        ]);

        $this->seed(PlatformChannelSeeder::class);

        $channel = NotificationChannel::query()
            ->whereNull('team_id')
            ->where('code', 'sam_sms')
            ->sole();

        $this->assertSame('SMS custom', $channel->name);
        $this->assertSame(['from' => '+15551112222'], $channel->config_json);
    }
}
