<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\AI\Actions\RecordOperatorVerdict;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\Incidents\Actions\AddIncidentComment;
use App\Domains\Incidents\Enums\CommentVisibility;
use App\Domains\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Confirmar" en la tarjeta de evaluación de IA: el operador etiqueta la
 * evaluación más reciente del evento del incidente (human-in-the-loop).
 */
class IncidentAIVerdictController extends Controller
{
    public function store(
        Request $request,
        Team $current_team,
        Incident $incident,
        RecordOperatorVerdict $recordOperatorVerdict,
        AddIncidentComment $addComment,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->authorize('comment', $incident);

        $validated = $request->validate([
            'verdict' => ['required', 'string', Rule::enum(OperatorVerdict::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($incident->related_event_id === null) {
            return response()->json(['message' => 'El incidente no tiene un evento con evaluación de IA.'], 422);
        }

        $verdict = OperatorVerdict::from($validated['verdict']);

        $evaluation = $recordOperatorVerdict->execute(
            teamId: (int) $incident->team_id,
            normalizedEventId: (int) $incident->related_event_id,
            verdict: $verdict,
            userId: $user->id,
            note: $validated['note'] ?? null,
        );

        if ($evaluation === null) {
            return response()->json(['message' => 'No hay evaluación de IA para este incidente.'], 422);
        }

        $addComment->execute(
            incident: $incident,
            user: $user,
            comment: $verdict === OperatorVerdict::Confirmed
                ? 'Evaluación de IA confirmada por el operador.'
                : 'El operador marcó la evaluación de IA como falso positivo.',
            visibility: CommentVisibility::AuditOnly,
        );

        return response()->json([
            'data' => [
                'evaluation_id' => $evaluation->id,
                'operator_verdict' => $evaluation->operator_verdict?->value,
                'operator_verdict_label' => $evaluation->operator_verdict?->label(),
                'operator_verdict_at' => $evaluation->operator_verdict_at?->toIso8601String(),
            ],
        ]);
    }
}
