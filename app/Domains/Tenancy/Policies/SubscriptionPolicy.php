<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'tenancy.billing.view', $team);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        $team = currentTeam();

        return $team
            && $subscription->team_id === $team->id
            && $this->authorizeAction->execute($user, 'tenancy.billing.view', $team);
    }

    public function update(User $user, Subscription $subscription): bool
    {
        $team = currentTeam();

        return $team
            && $subscription->team_id === $team->id
            && $this->authorizeAction->execute($user, 'tenancy.billing.manage', $team);
    }
}
