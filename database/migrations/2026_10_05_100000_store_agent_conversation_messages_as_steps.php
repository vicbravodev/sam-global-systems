<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel AI 1.0 stores each assistant turn as `steps` (one per model round
 * trip, each tool result on the call that produced it) and replaces
 * `approval_state` with a `status` column. Backfill follows the package's
 * 1.0 upgrade guide; `participant_index` now includes the agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_conversation_messages')) {
            return;
        }

        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->longText('steps')->nullable();
            $table->string('status', 25)->default('completed');
        });

        DB::table('agent_conversation_messages')->where('role', 'user')->update(['steps' => '[]']);

        DB::table('agent_conversation_messages')
            ->select('conversation_id')
            ->distinct()
            ->orderBy('conversation_id')
            ->chunk(100, function (Collection $conversations) {
                foreach ($conversations as $conversation) {
                    $this->backfill($conversation->conversation_id);
                }
            });

        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->longText('steps')->nullable(false)->change();
            $table->dropColumn(['tool_calls', 'tool_results', 'approval_state']);
            $table->dropIndex('participant_index');
            $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_conversation_messages')) {
            return;
        }

        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropIndex('participant_index');
            $table->index(['participant_type', 'participant_id'], 'participant_index');
            $table->text('tool_calls')->default('[]');
            $table->text('tool_results')->default('[]');
            $table->text('approval_state')->nullable();
            $table->dropColumn(['steps', 'status']);
        });
    }

    /**
     * Rewrite one conversation's assistant rows as steps, each result landing on the call that made it.
     */
    private function backfill(string $conversationId): void
    {
        $rows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get();

        // A result was recorded on the row of the request that produced it, which may be a later row than its call.
        $results = $rows->flatMap(fn (object $row) => $this->decoded($row->tool_results))->keyBy('id');

        foreach ($rows as $row) {
            $meta = $this->decoded($row->meta);

            $calls = collect($this->decoded($row->tool_calls))
                ->filter(fn (array $call) => $results->has($call['id'] ?? ''))
                ->map(fn (array $call) => [
                    ...$call,
                    'result' => $results[$call['id']]['result'] ?? null,
                    ...array_filter([
                        'denied' => $results[$call['id']]['denied'] ?? false,
                        'failed' => $results[$call['id']]['failed'] ?? false,
                    ]),
                ])
                ->values()
                ->all();

            $content = (string) $row->content;

            $steps = $calls !== [] && $content !== ''
                ? [$this->step('', $calls), $this->step($content, [], $meta['reasoning'] ?? '')]
                : [$this->step($content, $calls, $meta['reasoning'] ?? '')];

            unset($meta['provider_steps'], $meta['provider_content_blocks'], $meta['reasoning']);

            DB::table('agent_conversation_messages')->where('id', $row->id)->update([
                'steps' => json_encode($steps),
                'meta' => json_encode($meta),
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     */
    private function step(string $content, array $calls = [], string $reasoning = ''): array
    {
        return [
            'content' => $content,
            'tool_calls' => $calls,
            'reasoning' => $reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(?string $json): array
    {
        return is_array($decoded = json_decode($json ?? '', true)) ? $decoded : [];
    }
};
