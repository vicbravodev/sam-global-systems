<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionTemplate;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * UI audit P1-11: tenant SMS/email templates render `{{incident.code}}` as
 * the per-tenant reference (INC-00001), never the global id.
 */
class AutomationIncidentTemplateVariablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_code_renders_the_per_tenant_reference(): void
    {
        Bus::fake();

        $user = User::factory()->create(['phone' => '+5215555550201', 'phone_verified_at' => now()]);
        $team = $user->currentTeam;

        // Burn global ids in another tenant so id and number diverge.
        Incident::factory()->count(3)->create(['team_id' => Team::factory()->create()->id]);
        $incident = Incident::factory()->create(['team_id' => $team->id, 'title' => 'Pánico unidad 12']);

        $template = ActionTemplate::factory()->ofType(ActionType::SendSms)->create([
            'team_id' => $team->id,
            'channel' => 'sms',
            'subject_template' => null,
            'body_template' => '[SAM] {{incident.code}} {{incident.title}}',
        ]);

        $execution = ActionExecution::factory()->create([
            'team_id' => $team->id,
            'incident_id' => $incident->id,
            'action_template_id' => $template->id,
            'action_type' => ActionType::SendSms,
            'status' => ActionExecutionStatus::Queued,
            'target_type' => 'user',
            'target_reference' => (string) $user->id,
        ]);

        app(ExecuteAction::class)->execute($execution);

        $notification = Notification::withoutGlobalScopes()->where('team_id', $team->id)->firstOrFail();

        $this->assertSame('[SAM] INC-00001 Pánico unidad 12', $notification->body_preview);
    }
}
