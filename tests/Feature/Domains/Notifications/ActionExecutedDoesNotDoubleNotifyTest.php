<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionExecutedDoesNotDoubleNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_action_does_not_produce_a_second_notification(): void
    {
        $team = Team::factory()->create();
        $execution = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::SendEmail,
        ]);

        $before = Notification::query()->count();

        event(new ActionExecuted($execution));

        $this->assertSame($before, Notification::query()->count());
    }
}
