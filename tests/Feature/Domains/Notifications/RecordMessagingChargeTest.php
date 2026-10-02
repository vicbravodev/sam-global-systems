<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\RecordMessagingCharge;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * RecordMessagingCharge nunca lanza: el envío ya ocurrió. Si el registro del
 * cargo falla, queda narrado como `billing.messaging_charge.record_failed`
 * (el reconciliador no podrá cobrarlo, así que debe poder investigarse).
 */
class RecordMessagingChargeTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_records_the_charge_once_per_sid(): void
    {
        $team = Team::factory()->create();

        $first = $this->record($team->id, 'SM-cargo-1');
        $second = $this->record($team->id, 'SM-cargo-1');

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, MessagingCharge::withoutGlobalScopes()->where('provider_sid', 'SM-cargo-1')->count());
        $this->assertSame($team->id, $first->team_id);
        $this->assertSystemNotLogged('billing.messaging_charge.record_failed');
    }

    public function test_empty_sid_records_nothing(): void
    {
        $team = Team::factory()->create();

        $this->assertNull($this->record($team->id, ''));
        $this->assertSame(0, MessagingCharge::withoutGlobalScopes()->count());
    }

    public function test_a_charge_that_cannot_be_recorded_is_logged_instead_of_thrown(): void
    {
        $team = Team::factory()->create();

        MessagingCharge::creating(fn () => throw new RuntimeException('insert rechazado'));

        $result = $this->record($team->id, 'SM-cargo-roto', sourceId: 42);

        $this->assertNull($result);
        $this->assertSame(0, MessagingCharge::withoutGlobalScopes()->count());
        $this->assertSystemLogged('billing.messaging_charge.record_failed', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'record_failed'
            && $c['input']['team_id'] === $team->id
            && $c['input']['provider_sid'] === 'SM-cargo-roto'
            && $c['input']['source_type'] === MessagingChargeSource::NotificationDelivery->value
            && $c['input']['source_id'] === 42
            && isset($c['error']));
        $this->assertNoSensitiveDataLogged();
    }

    private function record(int $teamId, string $sid, ?int $sourceId = null): ?MessagingCharge
    {
        return app(RecordMessagingCharge::class)->execute(
            $teamId,
            $sid,
            MessagingResourceType::Message,
            MessagingChargeSource::NotificationDelivery,
            $sourceId,
            ChannelType::Sms,
            'queued',
            1,
        );
    }
}
