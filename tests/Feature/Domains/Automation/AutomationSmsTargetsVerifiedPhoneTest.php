<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * P2: una acción SMS a un usuario/rol lleva el teléfono VERIFICADO del
 * usuario (antes nunca ponía teléfono: el SMS no salía) y nunca uno sin
 * verificar.
 */
class AutomationSmsTargetsVerifiedPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_to_a_user_target_uses_only_the_verified_phone(): void
    {
        Bus::fake();

        $verified = User::factory()->create(['phone' => '+5215555550201', 'phone_verified_at' => now()]);
        $team = $verified->currentTeam;
        $unverified = User::factory()->create(['phone' => '+5215555550202', 'phone_verified_at' => null]);
        $team->members()->attach($unverified, ['role' => 'member']);

        $this->runSms($team->id, (string) $verified->id, 'a');
        $this->runSms($team->id, (string) $unverified->id, 'b');

        $recipients = Notification::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Notification $n) => $n->payload_json['recipients'][0]);

        $this->assertSame('+5215555550201', $recipients[0]['phone']);
        $this->assertNull($recipients[1]['phone']);
    }

    private function runSms(int $teamId, string $userId, string $suffix): void
    {
        $execution = ActionExecution::factory()->create([
            'team_id' => $teamId,
            'action_type' => ActionType::SendSms,
            'status' => ActionExecutionStatus::Queued,
            'target_type' => 'user',
            'target_reference' => $userId,
            'source_reference_id' => "sms-{$suffix}",
        ]);

        app(ExecuteAction::class)->execute($execution);
    }
}
