<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Listeners\EvaluateOnEventContextBuilt;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EvaluateOnEventContextBuiltListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_dispatches_evaluate_event_job_with_normalized_event_id(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);
        $snapshot = EventContextSnapshot::factory()->create([
            'team_id' => $user->currentTeam->id,
            'normalized_event_id' => $event->id,
        ]);
        $profile = OperationalContextProfile::factory()->create([
            'team_id' => $user->currentTeam->id,
            'normalized_event_id' => $event->id,
        ]);

        (new EvaluateOnEventContextBuilt)->handle(new EventContextBuilt($snapshot, $profile));

        Bus::assertDispatched(
            EvaluateEventJob::class,
            fn (EvaluateEventJob $job) => $job->normalizedEventId === $event->id,
        );
    }
}
