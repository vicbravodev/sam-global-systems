<?php

namespace App\Domains\Automation\Support;

use App\Domains\Access\Models\Role;
use App\Domains\Automation\Models\ActionTemplate;
use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Str;

/**
 * Traduce el destino crudo de un paso de automatización (`role|monitorista`,
 * `user|2`, `external|mesa-de-ayuda`) a una etiqueta humana para la UI.
 * Todas las búsquedas se limitan al team: un id de usuario ajeno nunca
 * revela su nombre, y los roles/plantillas sólo resuelven los del tenant o
 * los de plataforma (`team_id` null).
 */
class AutomationTargetLabels
{
    /** @var array<string, string> */
    private array $users;

    /** @var array<string, string> */
    private array $roles;

    /** @var array<string, string> */
    private array $templates;

    public function __construct(Team $team)
    {
        $this->users = $team->members()
            ->get(['users.id', 'users.name'])
            ->mapWithKeys(fn ($user): array => [(string) $user->id => (string) $user->name])
            ->all();

        // Roles RBAC sin BelongsToTenant (team_id nullable): idiom explícito
        // tenant + plataforma, con el del tenant ganando al de plataforma.
        $this->roles = Role::query()
            ->where(fn ($query) => $query->where('team_id', $team->id)->orWhereNull('team_id'))
            ->orderByRaw('team_id is null desc')
            ->get(['code', 'name'])
            ->mapWithKeys(fn (Role $role): array => [(string) $role->code => (string) $role->name])
            ->all();

        $this->templates = ActionTemplate::query()
            ->where(fn ($query) => $query->where('team_id', $team->id)->orWhereNull('team_id'))
            ->orderByRaw('team_id is null desc')
            ->get(['code', 'name'])
            ->mapWithKeys(fn (ActionTemplate $template): array => [(string) $template->code => (string) $template->name])
            ->all();
    }

    public function label(?string $type, ?string $reference): string
    {
        $name = $this->name($type, $reference);

        if ($name === null) {
            return '—';
        }

        return match ($type) {
            'role' => 'Rol: '.$name,
            'user' => $name,
            'email' => 'Correo: '.$name,
            'phone' => 'Teléfono: '.$name,
            'url' => 'URL: '.$name,
            default => 'Contacto externo: '.$name,
        };
    }

    /**
     * Sólo el nombre del destino, sin el prefijo de tipo ("Monitorista",
     * "Ana Operadora", "ops@cliente.mx"): la UI lo compone en frases como
     * "WhatsApp a Monitorista". Null cuando el paso no tiene destino.
     */
    public function name(?string $type, ?string $reference): ?string
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            return null;
        }

        return match ($type) {
            'role' => TeamRole::tryFrom($reference)?->label()
                ?? $this->roles[$reference]
                ?? Str::headline($reference),
            'user' => $this->users[$reference] ?? 'Usuario que ya no es miembro',
            'email', 'phone', 'url' => $reference,
            default => $this->templates[$reference] ?? Str::ucfirst(str_replace(['-', '_'], ' ', $reference)),
        };
    }
}
