<?php

namespace App\Models;

use App\Concerns\HasTeams;
use App\Domains\Notifications\Models\PushSubscription;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

// global_role y current_team_id NO son asignables en masa: se escriben sólo
// con forceFill desde SetGlobalRole / switchTeam / forceSwitchTeam.
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, TwoFactorAuthenticatable;

    /**
     * Normaliza un email para guardarlo o buscarlo: sin espacios y en
     * minúsculas. En Postgres `unique(email)` distingue mayúsculas, así que
     * sin esto «Foo@x.com» y «foo@x.com» serían dos cuentas distintas.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        return $email === null ? null : mb_strtolower(trim($email));
    }

    /**
     * Busca un usuario por email sin distinguir mayúsculas (cubre filas
     * históricas guardadas antes de la normalización).
     */
    public static function findByEmail(?string $email): ?self
    {
        $normalized = self::normalizeEmail($email);

        if ($normalized === null || $normalized === '') {
            return null;
        }

        return self::query()->whereRaw('lower(email) = ?', [$normalized])->first();
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => self::normalizeEmail($value),
        );
    }

    public function isSuperAdmin(): bool
    {
        return $this->global_role === 'super_admin';
    }

    /**
     * Canal `database` de Laravel Notifications: la tabla `notifications` es
     * del dominio Notifications (mensajería), así que las notificaciones in-app
     * del usuario viven en `user_notifications`.
     *
     * @return MorphMany<UserNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(UserNotification::class, 'notifiable')->latest();
    }

    /**
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Teléfono utilizable para SMS/WhatsApp/voz: sólo el verificado por OTP.
     * Un número sin verificar puede ser de un tercero y cada envío se paga.
     */
    public function verifiedPhone(): ?string
    {
        $phone = trim((string) $this->phone);

        return $phone !== '' && $this->phone_verified_at !== null ? $phone : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
