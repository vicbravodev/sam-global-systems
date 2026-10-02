<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Lista de "¿este cliente ya puede operar?" para la consola del super-admin.
 * Cada paso es un hecho verificable en base, en el orden en que el cliente
 * los cumple: entra su responsable, conecta su proveedor, el webhook (por el
 * que llegan los pánicos) valida firmas, vigila unidades, hay a quién llamar
 * y su zona horaria es la suya.
 */
class ResolveTenantSetupStatus
{
    /**
     * @return array{steps: list<array{key: string, label: string, done: bool, detail: string}>, completed: int, total: int}
     */
    public function execute(Team $team): array
    {
        return TenantContext::for($team->id, function () use ($team): array {
            $owner = $team->owner();

            $integration = TenantIntegration::query()
                ->where('team_id', $team->id)
                ->with(['provider', 'webhookEndpoint'])
                ->orderByDesc('id')
                ->get()
                ->sortByDesc(fn (TenantIntegration $i): bool => $i->isActive())
                ->first();

            $endpoint = $integration?->webhookEndpoint;
            $health = $endpoint?->signatureHealth();

            $monitored = Asset::query()
                ->where('team_id', $team->id)
                ->where('monitoring_state', AssetMonitoringState::Monitored)
                ->count();

            $reachable = Membership::query()
                ->where('team_id', $team->id)
                ->whereIn('role', [TeamRole::Owner->value, TeamRole::Admin->value, TeamRole::Member->value])
                ->whereHas('user', fn ($q) => $q->whereNotNull('phone')->where('phone', '!=', '')->whereNotNull('phone_verified_at'))
                ->count();

            $steps = [
                [
                    'key' => 'owner',
                    'label' => 'El responsable activó su cuenta',
                    'done' => $owner instanceof User && $owner->email_verified_at !== null,
                    'detail' => match (true) {
                        ! $owner instanceof User => 'El cliente no tiene responsable.',
                        $owner->email_verified_at === null => "Esperando a {$owner->email}. Puedes reenviarle el acceso en Miembros.",
                        default => "{$owner->name} ya entra a SAM.",
                    },
                ],
                [
                    'key' => 'integration',
                    'label' => 'Proveedor conectado',
                    'done' => $integration?->isActive() === true,
                    'detail' => $integration === null
                        ? 'Conecta Samsara en Integraciones (con su token de API).'
                        : ($integration->provider->name ?? 'Proveedor').($integration->isActive() ? ' activo.' : ' conectado pero inactivo.'),
                ],
                [
                    'key' => 'webhook',
                    'label' => 'Webhook de pánicos validando firmas',
                    'done' => in_array($health, [WebhookEndpoint::HEALTH_OK, WebhookEndpoint::HEALTH_WAITING], true),
                    'detail' => match ($health) {
                        null => 'Se crea al conectar el proveedor.',
                        WebhookEndpoint::HEALTH_PENDING_SECRET => 'Falta pegar la Secret Key de Samsara: sin ella se rechazan los pánicos.',
                        WebhookEndpoint::HEALTH_REJECTING => 'Los últimos webhooks se rechazaron por firma inválida.',
                        WebhookEndpoint::HEALTH_WAITING => 'Configurado; aún no llega ningún evento.',
                        default => 'Recibiendo eventos con firma válida.',
                    },
                ],
                [
                    'key' => 'assets',
                    'label' => 'Unidades vigiladas',
                    'done' => $monitored > 0,
                    'detail' => $monitored > 0
                        ? "{$monitored} ".($monitored === 1 ? 'unidad vigilada' : 'unidades vigiladas').'; se cobran por tracto-día.'
                        : 'Elige en Unidades qué tractos vigilar.',
                ],
                [
                    'key' => 'contacts',
                    'label' => 'Alguien a quién llamar',
                    'done' => $reachable > 0,
                    'detail' => $reachable > 0
                        ? "{$reachable} ".($reachable === 1 ? 'persona con teléfono verificado' : 'personas con teléfono verificado').'.'
                        : 'Nadie del equipo tiene teléfono verificado: la escalación por llamada y SMS no tendrá destinatario.',
                ],
                [
                    'key' => 'timezone',
                    'label' => 'Zona horaria del cliente',
                    'done' => $team->timezone !== null && $team->timezone !== '',
                    'detail' => $team->timezone !== null && $team->timezone !== ''
                        ? $team->timezone
                        : 'Sin definir: horario silencioso y reportes usan UTC.',
                ],
            ];

            return [
                'steps' => $steps,
                'completed' => count(array_filter($steps, fn (array $s): bool => $s['done'])),
                'total' => count($steps),
            ];
        });
    }
}
