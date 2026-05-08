<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTeams;
use App\Domains\Access\Models\UserPreference;
use App\Domains\Notifications\Models\UserPushToken;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'current_team_id', 'global_role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTeams, Notifiable, TwoFactorAuthenticatable;

    public function isSuperAdmin(): bool
    {
        return $this->global_role === 'super_admin';
    }

    /**
     * @return HasMany<UserPreference, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(UserPreference::class);
    }

    /**
     * @return HasMany<UserPushToken, $this>
     */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(UserPushToken::class);
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
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
