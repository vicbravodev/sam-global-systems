<?php

namespace App\Domains\Access\Models;

use App\Domains\Access\Enums\RoleScope;
use App\Models\Membership;
use App\Models\Team;
use Database\Factories\Domains\Access\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol RBAC. `team_id` null = rol de sistema (catálogo de plataforma, sólo el
 * super-admin lo modifica); `team_id` = rol personalizado de ese tenant.
 *
 * Sin BelongsToTenant a propósito (§2.1, NULLABLE_TEAM_ID_UNSCOPED): toda
 * consulta desde un tenant usa `visibleToTeam()`, que combina las dos filas.
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'code',
        'description',
        'scope',
        'is_system',
    ];

    /**
     * Prefijo que namespacea los códigos de roles personalizados por tenant:
     * el índice único global de `code` se conserva (migraciones additive-only)
     * y así dos tenants pueden usar el mismo slug.
     */
    public static function customCodeFor(int $teamId, string $slug): string
    {
        return "t{$teamId}-{$slug}";
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Roles que un tenant puede ver y asignar: los de sistema + los suyos.
     *
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeVisibleToTeam(Builder $query, int $teamId): Builder
    {
        return $query->where(function (Builder $query) use ($teamId) {
            $query->where(fn (Builder $q) => $q->whereNull('team_id')->where('is_system', true))
                ->orWhere('team_id', $teamId);
        });
    }

    public function isVisibleToTeam(int $teamId): bool
    {
        return ($this->team_id === null && $this->is_system) || $this->team_id === $teamId;
    }

    /**
     * ¿Puede el tenant dado editar/borrar este rol? Sólo sus propios roles
     * personalizados; los de sistema son inmutables para los tenants.
     */
    public function isOwnedByTeam(int $teamId): bool
    {
        return ! $this->is_system && $this->team_id === $teamId;
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function hasPermission(string $permissionCode): bool
    {
        return $this->permissions()->where('code', $permissionCode)->exists();
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeTenant(Builder $query): Builder
    {
        return $query->where('scope', RoleScope::Tenant);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'team_id' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
