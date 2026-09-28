<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use Database\Seeders\PlatformChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

        $this->assertSame(5, NotificationChannel::query()->count());
    }

    public function test_platform_channels_carry_no_tenant_and_no_credentials(): void
    {
        $this->seed(PlatformChannelSeeder::class);

        $this->assertFalse(Schema::hasColumn('notification_channels', 'team_id'));
        $this->assertTrue(NotificationChannel::query()->get()->every(fn (NotificationChannel $channel) => $channel->config_json === null));
    }

    public function test_does_not_overwrite_an_existing_platform_channel(): void
    {
        NotificationChannel::factory()->sms()->create([
            'code' => 'sam_sms',
            'name' => 'SMS custom',
            'config_json' => ['from' => '+15551112222'],
        ]);

        $this->seed(PlatformChannelSeeder::class);

        $channel = NotificationChannel::query()
            ->where('code', 'sam_sms')
            ->sole();

        $this->assertSame('SMS custom', $channel->name);
        $this->assertSame(['from' => '+15551112222'], $channel->config_json);
    }
}
