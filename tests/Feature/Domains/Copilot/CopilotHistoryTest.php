<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Support\CopilotHistory;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the model remembers: the last turns of the conversation, capped at
 * ~4 KB by dropping the oldest (never below two messages), and only rows of
 * the conversation's own tenant.
 */
class CopilotHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function message(CopilotConversation $conversation, string $content, bool $assistant = false, array $overrides = []): CopilotMessage
    {
        $factory = CopilotMessage::factory()->for($conversation, 'conversation');

        return ($assistant ? $factory->assistant() : $factory)->create(['content' => $content, ...$overrides]);
    }

    public function test_trims_the_oldest_turns_to_about_four_kilobytes(): void
    {
        $conversation = CopilotConversation::factory()->create();

        foreach (range(1, 6) as $i) {
            $this->message($conversation, "m{$i} ".str_repeat('x', 1200), assistant: $i % 2 === 0);
        }

        $history = app(CopilotHistory::class)->forConversation($conversation)['history'];

        $this->assertSame(['m4', 'm5', 'm6'], array_map(fn (array $m) => strtok($m['content'], ' '), $history));
        $this->assertSame(['assistant', 'user', 'assistant'], array_column($history, 'role'));
        $this->assertLessThanOrEqual(4000, mb_strlen((string) json_encode($history, JSON_UNESCAPED_UNICODE)));
    }

    public function test_always_keeps_the_last_two_messages_even_when_they_exceed_the_cap(): void
    {
        $conversation = CopilotConversation::factory()->create();

        foreach (range(1, 4) as $i) {
            $this->message($conversation, "m{$i} ".str_repeat('y', 3000), assistant: $i % 2 === 0);
        }

        $history = app(CopilotHistory::class)->forConversation($conversation)['history'];

        $this->assertSame(['m3', 'm4'], array_map(fn (array $m) => strtok($m['content'], ' '), $history));
    }

    public function test_reads_only_rows_of_the_conversations_tenant(): void
    {
        $conversation = CopilotConversation::factory()->create();
        $other = Team::factory()->create();

        $this->message($conversation, 'propia');
        $this->message($conversation, 'respuesta propia', assistant: true, overrides: [
            'context_json' => ['facts_digest' => 'T555 45%', 'resolved' => ['asset_id' => 11]],
        ]);
        // A row pointing at this conversation but stamped with another tenant never reaches the model.
        $this->message($conversation, 'ajena', assistant: true, overrides: [
            'team_id' => $other->id,
            'context_json' => ['resolved' => ['asset_id' => 99]],
        ]);

        $memory = app(CopilotHistory::class)->forConversation($conversation);

        $this->assertSame(['propia', "respuesta propia\n[datos consultados: T555 45%]"], array_column($memory['history'], 'content'));
        $this->assertSame(11, $memory['previousAssetId']);
    }
}
