<?php

namespace App\Http\Requests\Concerns;

use App\Domains\Automation\Enums\ActionType;
use App\Support\Http\OutboundUrlGuard;
use App\Support\TeamMembers;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Un paso de workflow que apunta a un usuario (target_type=user, o la acción
 * assign_incident, que usa target_reference como id del asignado) debe
 * apuntar a un miembro del team: los ids de usuario son globales y, sin esto,
 * un tenant manda datos de sus incidentes a usuarios de otro.
 *
 * Un paso call_webhook usa target_reference como URL: debe pasar
 * OutboundUrlGuard (SSRF).
 */
trait ValidatesWorkflowStepTargets
{
    use ResolvesCurrentTeam;

    protected function stepTargetRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $index = (int) explode('.', $attribute)[1];
            $step = (array) $this->input("steps_json.{$index}", []);

            if (($step['action_type'] ?? null) === 'call_webhook') {
                if (! is_string($value) || ! app(OutboundUrlGuard::class)->isSafe($value)) {
                    $fail('La URL debe ser https y apuntar a un servidor público.');
                }

                return;
            }

            $targetsUser = ($step['target_type'] ?? null) === 'user'
                || (($step['action_type'] ?? null) === 'assign_incident' && in_array($step['target_type'] ?? null, [null, '', 'user'], true));

            if (! $targetsUser) {
                return;
            }

            $userId = filter_var($value, FILTER_VALIDATE_INT);
            $teamId = $this->currentTeamId();

            if ($userId === false || $teamId === null || ! TeamMembers::isMember($teamId, $userId)) {
                $fail('El usuario seleccionado no pertenece a este equipo.');
            }
        };
    }

    /**
     * Un tipo de acción real y ejecutable: las diferidas (ActionType::isDeferred)
     * se rechazan con un mensaje propio antes de la validación del enum.
     *
     * @return list<mixed>
     */
    protected function stepActionTypeRules(): array
    {
        return [
            Rule::notIn(ActionType::deferredValues()),
            Rule::enum(ActionType::class),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function stepActionTypeMessages(): array
    {
        return [
            'steps_json.*.action_type.not_in' => ActionType::DEFERRED_MESSAGE,
        ];
    }
}
