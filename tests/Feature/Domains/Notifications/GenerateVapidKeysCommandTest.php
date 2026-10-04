<?php

namespace Tests\Feature\Domains\Notifications;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GenerateVapidKeysCommandTest extends TestCase
{
    public function test_prints_a_fresh_vapid_key_pair_for_the_env_file(): void
    {
        $exitCode = Artisan::call('sam:vapid-keys');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{80,}$/m', $output);
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{40,}$/m', $output);
    }

    public function test_config_reads_vapid_keys_from_env(): void
    {
        $this->assertArrayHasKey('subject', config('webpush.vapid'));
        $this->assertArrayHasKey('public_key', config('webpush.vapid'));
        $this->assertArrayHasKey('private_key', config('webpush.vapid'));
    }
}
