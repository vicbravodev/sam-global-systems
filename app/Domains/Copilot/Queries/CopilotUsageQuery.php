<?php

namespace App\Domains\Copilot\Queries;

use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Usage analytics of SAM Copilot for one tenant: volume, tokens, cost,
 * latency, satisfaction and who is using it. Every figure is computed from
 * the assistant turns, which carry the metering of the whole turn.
 */
class CopilotUsageQuery
{
    private const RECENT_LIMIT = 20;

    public function __construct(private readonly CopilotQuotaQuery $quota) {}

    /**
     * @return array<string, mixed>
     */
    public function forTeam(int $teamId, int $days = 30): array
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays($days - 1)->startOfDay();

        $base = fn () => CopilotMessage::query()
            ->where('copilot_messages.team_id', $teamId)
            ->where('copilot_messages.role', CopilotMessageRole::Assistant)
            ->where('copilot_messages.created_at', '>=', $from);

        // Aggregate rows, not messages: read them as plain rows (toBase keeps
        // the tenant scope) so no aliased column goes through a model cast.
        $totals = $base()
            ->toBase()
            ->selectRaw('COUNT(*) as queries')
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(cost_estimate), 0) as cost')
            ->selectRaw('COALESCE(AVG(latency_ms), 0) as avg_latency')
            ->selectRaw('SUM(CASE WHEN feedback = 1 THEN 1 ELSE 0 END) as positive')
            ->selectRaw('SUM(CASE WHEN feedback = -1 THEN 1 ELSE 0 END) as negative')
            // Un agregado sin GROUP BY siempre devuelve exactamente una fila.
            ->sole();

        // Same identity as byUser(): the conversation owner of each metered
        // assistant turn, so the headline count matches the per-user table.
        $activeUsers = $base()
            ->join('copilot_conversations', 'copilot_conversations.id', '=', 'copilot_messages.copilot_conversation_id')
            ->where('copilot_conversations.team_id', $teamId)
            ->distinct()
            ->count('copilot_conversations.user_id');

        $daily = $base()
            ->toBase()
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as queries')
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as tokens')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $from->addDays($i)->toDateString();
            $series[] = [
                'date' => $day,
                'queries' => (int) ($daily[$day]->queries ?? 0),
                'tokens' => (int) ($daily[$day]->tokens ?? 0),
            ];
        }

        $byIntent = $base()
            ->selectRaw('intent, COUNT(*) as queries')
            ->groupBy('intent')
            ->orderByDesc('queries')
            ->get()
            ->map(fn ($row) => [
                'intent' => $row->intent?->value,
                'label' => $row->intent instanceof CopilotIntent ? $row->intent->label() : 'Sin clasificar',
                'queries' => (int) $row->getAttribute('queries'),
            ])
            ->all();

        $byChannel = $base()
            ->selectRaw('channel, COUNT(*) as queries')
            ->groupBy('channel')
            ->pluck('queries', 'channel')
            ->map(fn ($n) => (int) $n)
            ->all();

        $byUser = $this->byUser($teamId, $from);

        // The question of each answer is the latest user turn before it in the
        // same conversation, resolved in the same query (no per-row lookup).
        $question = DB::table('copilot_messages as question')
            ->select('question.content')
            ->whereColumn('question.copilot_conversation_id', 'copilot_messages.copilot_conversation_id')
            ->whereColumn('question.id', '<', 'copilot_messages.id')
            ->where('question.team_id', $teamId)
            ->where('question.role', CopilotMessageRole::User->value)
            ->orderByDesc('question.id')
            ->limit(1);

        $recent = $base()
            ->select('copilot_messages.*')
            ->selectSub($question, 'question_content')
            ->with(['conversation.user'])
            ->orderByDesc('copilot_messages.id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(function (CopilotMessage $answer): array {
                return [
                    'id' => (int) $answer->id,
                    'question' => (string) $answer->getAttribute('question_content'),
                    'user' => $answer->conversation?->user?->name,
                    'intent' => $answer->intent?->label(),
                    'channel' => $answer->channel,
                    'model' => $answer->model,
                    'inputTokens' => $answer->input_tokens,
                    'outputTokens' => $answer->output_tokens,
                    'cost' => (float) $answer->cost_estimate,
                    'latencyMs' => $answer->latency_ms,
                    'feedback' => $answer->feedback,
                    'createdAt' => $answer->created_at?->toIso8601String(),
                ];
            })
            ->all();

        $rated = (int) $totals->positive + (int) $totals->negative;

        return [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'days' => $days],
            'totals' => [
                'queries' => (int) $totals->queries,
                'activeUsers' => $activeUsers,
                'inputTokens' => (int) $totals->input_tokens,
                'outputTokens' => (int) $totals->output_tokens,
                'cost' => round((float) $totals->cost, 4),
                'avgLatencyMs' => (int) round((float) $totals->avg_latency),
                'avgTokensPerQuery' => (int) $totals->queries > 0
                    ? (int) round(((int) $totals->input_tokens + (int) $totals->output_tokens) / (int) $totals->queries)
                    : 0,
                'satisfaction' => $rated > 0 ? round((int) $totals->positive / $rated * 100) : null,
                'rated' => $rated,
            ],
            'series' => $series,
            'byIntent' => $byIntent,
            'byChannel' => $byChannel,
            'byUser' => $byUser,
            'recent' => $recent,
            'quota' => $this->quota->forTeam($teamId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byUser(int $teamId, CarbonImmutable $from): array
    {
        $rows = CopilotMessage::query()
            ->join('copilot_conversations', 'copilot_conversations.id', '=', 'copilot_messages.copilot_conversation_id')
            ->where('copilot_messages.team_id', $teamId)
            ->where('copilot_conversations.team_id', $teamId)
            ->where('copilot_messages.role', CopilotMessageRole::Assistant)
            ->where('copilot_messages.created_at', '>=', $from)
            ->toBase()
            ->groupBy('copilot_conversations.user_id')
            ->selectRaw('copilot_conversations.user_id as user_id')
            ->selectRaw('COUNT(*) as queries')
            ->selectRaw('COALESCE(SUM(copilot_messages.input_tokens + copilot_messages.output_tokens), 0) as tokens')
            ->selectRaw('COALESCE(SUM(copilot_messages.cost_estimate), 0) as cost')
            ->selectRaw('MAX(copilot_messages.created_at) as last_used_at')
            ->orderByDesc('queries')
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id');

        $byUser = $rows->map(fn ($row) => [
            'userId' => (int) $row->user_id,
            'name' => $users[$row->user_id]->name ?? 'Usuario eliminado',
            'email' => $users[$row->user_id]->email ?? null,
            'queries' => (int) $row->queries,
            'tokens' => (int) $row->tokens,
            'cost' => round((float) $row->cost, 4),
            'lastUsedAt' => $row->last_used_at ? CarbonImmutable::parse($row->last_used_at)->toIso8601String() : null,
        ])->all();

        return array_values($byUser);
    }
}
