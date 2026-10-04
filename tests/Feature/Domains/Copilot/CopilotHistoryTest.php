<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Support\CopilotHistory;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the model remembers: several turns of the conversation (older
 * answers condensed), capped at ~10 KB by dropping the oldest (never below
 * two messages), the questions already asked, and only rows of the
 * conversation's own tenant.
 */
class CopilotHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function message(CopilotConversation $conversation, string $content, bool $assistant = false, array $overrides = []): CopilotMessage
    {
        $factory = CopilotMessage::factory()->for($conversation, 'conversation');

        return ($assistant ? $factory->assistant() : $factory)->create(['content' => $content, ...$overrides]);
    }

    public function test_keeps_several_turns_condensing_older_answers(): void
    {
        $conversation = CopilotConversation::factory()->create();

        foreach (range(1, 8) as $i) {
            // Real questions are short; answers carry the weight.
            $this->message($conversation, "m{$i} ".str_repeat('x', $i % 2 === 0 ? 1200 : 100), assistant: $i % 2 === 0, overrides: $i % 2 === 0
                ? ['context_json' => ['facts_digest' => "d{$i} ".str_repeat('z', 1000)]]
                : []);
        }

        $history = app(CopilotHistory::class)->forConversation($conversation)['history'];

        // Four full exchanges survive (the old cap kept only the last one).
        $this->assertSame(['m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7', 'm8'], array_map(fn (array $m) => strtok($m['content'], ' '), $history));

        // The latest answer goes whole, with its digest whole.
        $this->assertStringContainsString(str_repeat('x', 1200)."\n[datos consultados: d8 ".str_repeat('z', 1000).']', $history[7]['content']);

        // Older answers are condensed: opening + short digest, cut with "…".
        $this->assertLessThan(1100, mb_strlen($history[1]['content']));
        $this->assertStringContainsString('…', $history[1]['content']);
        $this->assertStringContainsString('[datos consultados: d2 ', $history[1]['content']);

        // Questions always go whole.
        $this->assertSame('m7 '.str_repeat('x', 100), $history[6]['content']);
        $this->assertLessThanOrEqual(10000, mb_strlen((string) json_encode($history, JSON_UNESCAPED_UNICODE)));
    }

    public function test_drops_the_oldest_messages_past_ten_kilobytes(): void
    {
        $conversation = CopilotConversation::factory()->create();

        foreach (range(1, 12) as $i) {
            $this->message($conversation, "m{$i} ".str_repeat('q', 1800));
        }

        $history = app(CopilotHistory::class)->forConversation($conversation)['history'];

        $this->assertSame('m12', strtok(end($history)['content'], ' '));
        $this->assertLessThan(12, count($history));
        $this->assertLessThanOrEqual(10000, mb_strlen((string) json_encode($history, JSON_UNESCAPED_UNICODE)));
    }

    public function test_always_keeps_the_last_two_messages_even_when_they_exceed_the_cap(): void
    {
        $conversation = CopilotConversation::factory()->create();

        foreach (range(1, 4) as $i) {
            $this->message($conversation, "m{$i} ".str_repeat('y', 6000), assistant: $i % 2 === 0);
        }

        $history = app(CopilotHistory::class)->forConversation($conversation)['history'];

        $this->assertSame(['m3', 'm4'], array_map(fn (array $m) => strtok($m['content'], ' '), $history));
    }

    public function test_returns_the_questions_already_asked(): void
    {
        $conversation = CopilotConversation::factory()->create();

        $this->message($conversation, '¿Dónde está la T-77?');
        $this->message($conversation, 'En Apodaca.', assistant: true);
        $this->message($conversation, '¿Y su combustible?');
        $this->message($conversation, '58 %.', assistant: true);

        $this->assertSame(
            ['¿Dónde está la T-77?', '¿Y su combustible?'],
            app(CopilotHistory::class)->forConversation($conversation)['askedQuestions'],
        );
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
