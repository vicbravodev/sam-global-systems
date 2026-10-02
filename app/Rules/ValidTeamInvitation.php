<?php

namespace App\Rules;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\SystemLog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidTeamInvitation implements ValidationRule
{
    public function __construct(protected ?User $user)
    {
        //
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof TeamInvitation || ! $this->user instanceof User) {
            $this->reject('not_resolvable', $value);

            $fail(__('This invitation was sent to a different email address.'));

            return;
        }

        if ($value->isAccepted()) {
            $this->reject('already_accepted', $value);

            $fail(__('This invitation has already been accepted.'));

            return;
        }

        if ($value->isExpired()) {
            $this->reject('expired', $value);

            $fail(__('This invitation has expired.'));

            return;
        }

        if (strtolower($value->email) !== strtolower($this->user->email)) {
            $this->reject('email_mismatch', $value);

            $fail(__('This invitation was sent to a different email address.'));
        }
    }

    /**
     * Rechazo al aceptar (antes de `AcceptTeamInvitation`): sólo ids, nunca
     * los correos comparados.
     */
    private function reject(string $reason, mixed $invitation): void
    {
        SystemLog::skipped('access.invitation.rejected', reason: $reason, input: [
            'team_id' => $invitation instanceof TeamInvitation ? $invitation->team_id : null,
            'invitation_id' => $invitation instanceof TeamInvitation ? $invitation->id : null,
            'user_id' => $this->user?->id,
            'stage' => 'accept',
        ]);
    }
}
