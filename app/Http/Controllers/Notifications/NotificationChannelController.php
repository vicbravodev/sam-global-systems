<?php

namespace App\Http\Controllers\Notifications;

use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\TenantChannelToggle;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La mensajería la opera SAM con credenciales de plataforma (env): los
 * tenants no crean ni editan canales. Lo único que un tenant controla es el
 * switch on/off de los canales de plataforma para su propio equipo (V2-B1).
 */
class NotificationChannelController extends Controller
{
    /**
     * Switch a SAM platform channel on/off for the current tenant
     * (Roadmap V2-B1). The tenant never touches the channel itself — only its
     * own `tenant_channel_toggles` row. Idempotent upsert.
     */
    public function toggle(Request $request, Team $current_team, NotificationChannel $channel): JsonResponse
    {
        $this->authorize('toggleGlobal', $channel);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $toggle = TenantChannelToggle::query()->updateOrCreate(
            [
                'team_id' => $current_team->id,
                'notification_channel_id' => $channel->id,
            ],
            ['enabled' => (bool) $validated['enabled']],
        );

        return response()->json(['data' => [
            'channel_id' => (int) $channel->id,
            'enabled' => (bool) $toggle->enabled,
        ]]);
    }
}
