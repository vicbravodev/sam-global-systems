<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or revokes the global super-admin role. Super-admins are never managed
 * from the UI (nobody can self-promote); they are provisioned here or via seeder.
 */
class PromoteSuperAdmin extends Command
{
    protected $signature = 'sam:promote-super-admin {email : Email of the user} {--demote : Revoke the super-admin role instead of granting it}';

    protected $description = 'Grant or revoke the global super_admin role for a user';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user found with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $demote = (bool) $this->option('demote');

        $user->update(['global_role' => $demote ? null : 'super_admin']);

        $this->info($demote
            ? "Revoked super-admin from {$user->email}."
            : "Granted super-admin to {$user->email}.");

        return self::SUCCESS;
    }
}
