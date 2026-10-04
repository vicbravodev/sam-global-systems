<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Notifications\Models\PushSubscription;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePushSubscriptionRequest;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Alta y baja del dispositivo actual para avisos de SAM. Un endpoint es una
 * sola fila: volver a registrarlo la reasigna al usuario y team actuales
 * (navegador compartido o cambio de team), así el dueño anterior deja de
 * recibir ahí.
 */
class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $team = currentTeam();
        abort_if($team === null, 403);

        $endpoint = (string) $request->validated('endpoint');
        $attributes = [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'public_key' => (string) $request->validated('keys.p256dh'),
            'auth_token' => (string) $request->validated('keys.auth'),
            'content_encoding' => (string) ($request->validated('content_encoding') ?? 'aes128gcm'),
            'device_label' => self::deviceLabel((string) $request->userAgent()),
        ];

        try {
            [$subscription, $created, $moved] = self::upsert($endpoint, $attributes);
        } catch (UniqueConstraintViolationException) {
            // Doble POST simultáneo del mismo navegador: la otra petición ganó
            // el INSERT; repetimos por el camino de actualización.
            [$subscription, $created, $moved] = self::upsert($endpoint, $attributes);
        }

        $pruned = self::pruneExcess($team->id, $user->id, $subscription->id);

        SystemLog::ok(
            'notifications.push_subscription.registered',
            input: ['team_id' => $team->id, 'user_id' => $user->id, 'subscription_id' => $subscription->id],
            calc: ['created' => $created, 'moved' => $moved, 'pruned' => $pruned, 'max_per_user' => PushSubscription::MAX_PER_USER],
        );

        return response()->json(['id' => $subscription->id], $created ? 201 : 200);
    }

    public function destroy(Request $request, #[CurrentUser] User $user): Response
    {
        $validated = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $team = currentTeam();
        abort_if($team === null, 403);

        $deleted = PushSubscription::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($validated['endpoint']))
            ->delete();

        SystemLog::ok(
            'notifications.push_subscription.removed',
            input: ['team_id' => $team->id, 'user_id' => $user->id],
            result: ['deleted' => $deleted],
        );

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{PushSubscription, bool, bool}
     */
    private static function upsert(string $endpoint, array $attributes): array
    {
        // Buscamos fuera del scope a propósito: el mismo navegador pudo
        // suscribirse antes con otro usuario o team y debe pasar a este.
        $subscription = PushSubscription::withoutGlobalScopes()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->first();

        $created = $subscription === null;
        $moved = ! $created
            && ($subscription->user_id !== $attributes['user_id'] || $subscription->team_id !== $attributes['team_id']);

        $subscription ??= new PushSubscription;
        $subscription->fill($attributes)->save();

        return [$subscription, $created, $moved];
    }

    /**
     * Tope de dispositivos por usuario y team: se quedan los más recientes
     * (por último uso o actualización) y nunca el que se acaba de guardar.
     */
    private static function pruneExcess(int $teamId, int $userId, int $keepId): int
    {
        $keep = PushSubscription::query()
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->whereKeyNot($keepId)
            ->orderByRaw('COALESCE(last_used_at, updated_at) DESC')
            ->orderByDesc('id')
            ->limit(PushSubscription::MAX_PER_USER - 1)
            ->pluck('id')
            ->push($keepId)
            ->all();

        return PushSubscription::query()
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->whereKeyNot($keep)
            ->delete();
    }

    /**
     * Etiqueta humana del dispositivo para "Mis avisos"; nunca el user-agent
     * completo (huella del navegador).
     */
    private static function deviceLabel(string $userAgent): ?string
    {
        $device = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Windows') => 'Windows',
            default => null,
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        if ($device === 'iPhone' || $device === 'iPad') {
            return $device;
        }

        $parts = array_filter([$device, $browser]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
