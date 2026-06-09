<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\TenantBranding;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Updates a tenant's identity (name) and branding (display name, colors, logo).
 * Branding lives in a 1:1 TenantBranding row created on demand.
 */
class UpdateTenant
{
    /**
     * @param  array<string, mixed>  $attrs
     */
    public function execute(Team $team, array $attrs): Team
    {
        return DB::transaction(function () use ($team, $attrs) {
            if (array_key_exists('name', $attrs) && $attrs['name'] !== null) {
                $team->update(['name' => $attrs['name']]);
            }

            $brandingKeys = ['display_name', 'primary_color', 'secondary_color', 'logo_url'];
            $branding = array_intersect_key($attrs, array_flip($brandingKeys));

            if ($branding !== []) {
                TenantBranding::withoutGlobalScopes()->updateOrCreate(
                    ['team_id' => $team->id],
                    $branding,
                );
            }

            return $team->fresh();
        });
    }
}
