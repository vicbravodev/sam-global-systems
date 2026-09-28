<?php

namespace App\Domains\Copilot\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Models\User;

/**
 * SAM Copilot access. `copilot.use` also resolves the `copilot` tenant
 * feature and the subscription state inside AuthorizeAction, so turning the
 * module off for a tenant (or suspending it) closes every endpoint here.
 * Conversations are private: only their owner, inside their tenant, sees them.
 */
class CopilotConversationPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'copilot.use', $team);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, CopilotConversation $conversation): bool
    {
        $team = currentTeam();

        return $team
            && $conversation->team_id === $team->id
            && $conversation->user_id === $user->id
            && $this->authorizeAction->execute($user, 'copilot.use', $team);
    }

    public function update(User $user, CopilotConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function delete(User $user, CopilotConversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function viewUsage(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'copilot.usage.view', $team);
    }
}
