<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Queries\CopilotUsageQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class CopilotUsageQueryTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    public function test_recent_list_respects_the_day_window(): void
    {
        $conversation = CopilotConversation::factory()->create();

        $this->turn($conversation, 'pregunta vieja', now()->subDays(40));
        $this->turn($conversation, 'pregunta reciente', now()->subDay());

        $usage = app(CopilotUsageQuery::class)->forTeam($conversation->team_id, 7);

        $this->assertSame(['pregunta reciente'], array_column($usage['recent'], 'question'));
        $this->assertSame(1, $usage['totals']['queries']);
    }

    public function test_recent_list_pairs_each_answer_with_its_question(): void
    {
        $conversation = CopilotConversation::factory()->create();

        $this->turn($conversation, 'primera', now()->subHours(3));
        $this->turn($conversation, 'segunda', now()->subHours(2));

        $usage = app(CopilotUsageQuery::class)->forTeam($conversation->team_id, 7);

        $this->assertSame(['segunda', 'primera'], array_column($usage['recent'], 'question'));
    }

    public function test_recent_list_does_not_query_once_per_row(): void
    {
        $owner = User::factory()->create();
        $conversation = CopilotConversation::factory()->create(['user_id' => $owner->id]);

        $this->turn($conversation, 'q0', now()->subHour());
        $fewRows = $this->countQueries(fn () => app(CopilotUsageQuery::class)->forTeam($conversation->team_id, 7));

        for ($i = 1; $i <= 10; $i++) {
            $this->turn(CopilotConversation::factory()->create(['user_id' => $owner->id]), "q{$i}", now()->subMinutes($i));
        }
        $manyRows = $this->countQueries(fn () => app(CopilotUsageQuery::class)->forTeam($conversation->team_id, 7));

        $this->assertSame($fewRows, $manyRows);
    }

    public function test_active_users_and_per_user_usage_count_the_same_people(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create(['current_team_id' => $alice->current_team_id]);

        // A channel turn whose user message carries no user_id: the old
        // active-users count missed it while byUser attributed it to Bob.
        $this->turn(CopilotConversation::factory()->create(['user_id' => $alice->id]), 'hola', now()->subHour());
        $bobs = CopilotConversation::factory()->create(['user_id' => $bob->id, 'team_id' => $alice->current_team_id]);
        $this->turn($bobs, 'via whatsapp', now()->subHour(), questionUserId: null);

        // Outside the window: neither count includes it.
        $this->turn(CopilotConversation::factory()->create(['user_id' => User::factory()->create(['current_team_id' => $alice->current_team_id])->id, 'team_id' => $alice->current_team_id]), 'viejo', now()->subDays(60));

        $usage = app(CopilotUsageQuery::class)->forTeam($alice->current_team_id, 30);

        $this->assertSame(2, $usage['totals']['activeUsers']);
        $this->assertCount(2, $usage['byUser']);
        $this->assertEqualsCanonicalizing([$alice->id, $bob->id], array_column($usage['byUser'], 'userId'));
    }

    public function test_usage_never_includes_another_tenant(): void
    {
        $mine = CopilotConversation::factory()->create();
        $theirs = CopilotConversation::factory()->create();

        $this->turn($mine, 'mia', now()->subHour());
        $this->turn($theirs, 'ajena', now()->subMinutes(5));

        $usage = $this->assertNoTenantLeak($mine->team_id, fn () => app(CopilotUsageQuery::class)->forTeam($mine->team_id, 7));

        $this->assertSame(['mia'], array_column($usage['recent'], 'question'));
        $this->assertSame(1, $usage['totals']['activeUsers']);
        $this->assertSame([$mine->user_id], array_column($usage['byUser'], 'userId'));
    }

    private function turn(CopilotConversation $conversation, string $question, mixed $at, mixed $questionUserId = false): void
    {
        $attributes = ['copilot_conversation_id' => $conversation->id, 'content' => $question, 'created_at' => $at];

        if ($questionUserId !== false) {
            $attributes['user_id'] = $questionUserId;
        }

        CopilotMessage::factory()->create($attributes);
        CopilotMessage::factory()->assistant()->create([
            'copilot_conversation_id' => $conversation->id,
            'created_at' => $at->copy()->addSecond(),
        ]);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
