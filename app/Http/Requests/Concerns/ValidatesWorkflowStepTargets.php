<?php

namespace App\Http\Requests\Concerns;

use App\Support\Http\OutboundUrlGuard;
use App\Support\TeamMembers;
use Closure;

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
}
