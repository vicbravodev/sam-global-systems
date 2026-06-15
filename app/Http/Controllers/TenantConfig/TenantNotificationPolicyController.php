<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\TenantConfig\Actions\SnapshotTenantConfig;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantConfig\UpdateTenantNotificationPoliciesRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TenantNotificationPolicyController extends Controller
{
    public function __construct(
        private readonly SnapshotTenantConfig $snapshotTenantConfig,
    ) {}

    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', TenantNotificationPolicy::class);

        $policies = TenantNotificationPolicy::withoutGlobalScopes()
            ->where('team_id', $current_team->id)
            ->orderBy('policy_code')
            ->get();

        return response()->json(['data' => $policies]);
    }

    public function update(UpdateTenantNotificationPoliciesRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('update', TenantNotificationPolicy::class);

        $persisted = DB::transaction(function () use ($request, $current_team) {
            $items = [];
            foreach ($request->validated('policies') as $payload) {
                $items[] = TenantNotificationPolicy::withoutGlobalScopes()->updateOrCreate(
                    [
                        'team_id' => $current_team->id,
                        'policy_code' => $payload['policy_code'],
                    ],
                    [
                        'notification_type' => $payload['notification_type'] ?? null,
                        'priority' => $payload['priority'] ?? null,
                        'allowed_channels_json' => $payload['allowed_channels'],
                        'fallback_channels_json' => $payload['fallback_channels'] ?? null,
                        'recipient_rules_json' => $payload['recipient_rules'] ?? null,
                        'quiet_hours_json' => $payload['quiet_hours'] ?? null,
                        'escalation_rules_json' => $payload['escalation_rules'] ?? null,
                        'is_active' => $payload['is_active'] ?? true,
                    ],
                );
            }

            return $items;
        });

        foreach ($persisted as $policy) {
            Cache::forget(CacheKeys::notificationPolicy($current_team->id, $policy->notification_type, $policy->priority));
        }

        // D-18: guardar políticas de notificación también registra una versión.
        $userId = $request->user()?->id;
        $this->snapshotTenantConfig->execute(
            $current_team->id,
            $userId ? SettingUpdatedByType::User : SettingUpdatedByType::System,
            $userId,
        );

        return response()->json(['data' => $persisted]);
    }
}
