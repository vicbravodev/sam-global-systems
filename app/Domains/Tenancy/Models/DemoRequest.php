<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\DemoRequestStatus;
use App\Models\User;
use Database\Factories\Domains\Tenancy\DemoRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Solicitud de demo enviada desde el sitio público. Fila de plataforma (sin
 * team_id): el prospecto aún no es cliente y sólo la consola de super-admin
 * la lee.
 *
 * @property int $id
 * @property string $name
 * @property string $company
 * @property string $email
 * @property ?string $phone
 * @property string $fleet_size
 * @property ?string $message
 * @property DemoRequestStatus $status
 * @property ?int $handled_by_user_id
 * @property ?Carbon $status_changed_at
 * @property int $notified_recipients
 * @property ?Carbon $notified_at
 * @property ?string $ip_address
 */
class DemoRequest extends Model
{
    /** @use HasFactory<DemoRequestFactory> */
    use HasFactory;

    /** Rangos de flota que ofrece el formulario. */
    public const array FLEET_SIZES = ['1-10', '11-50', '51-200', '201-500', '500+'];

    protected $fillable = [
        'name',
        'company',
        'email',
        'phone',
        'fleet_size',
        'message',
        'status',
        'handled_by_user_id',
        'status_changed_at',
        'notified_recipients',
        'notified_at',
        'ip_address',
    ];

    protected $attributes = [
        'status' => 'new',
        'notified_recipients' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DemoRequestStatus::class,
            'status_changed_at' => 'datetime',
            'notified_at' => 'datetime',
            'notified_recipients' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    protected static function newFactory(): DemoRequestFactory
    {
        return DemoRequestFactory::new();
    }
}
