<?php

namespace App\Domains\Assets\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Integrations\Models\TenantIntegration;
use Database\Factories\Domains\Assets\TelematicsFeedCursorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one integration's stats feed was left, and how healthy following it
 * is. `end_cursor` only moves forward in the transaction that stores the data
 * it covers, so a crash replays a window instead of skipping it.
 */
class TelematicsFeedCursor extends Model
{
    /** @use HasFactory<TelematicsFeedCursorFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'tenant_integration_id',
        'feed',
        'end_cursor',
        'last_polled_at',
        'last_success_at',
        'last_data_at',
        'consecutive_failures',
        'paused_until',
        'last_error',
        'last_cycle_json',
    ];

    /**
     * @return BelongsTo<TenantIntegration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(TenantIntegration::class, 'tenant_integration_id');
    }

    /**
     * Seconds since the newest point this feed delivered, or null before the
     * first one.
     */
    public function lagSeconds(): ?int
    {
        return $this->last_data_at !== null
            ? max(0, (int) $this->last_data_at->diffInSeconds(now()))
            : null;
    }

    /**
     * @phpstan-assert-if-true !null $this->paused_until
     */
    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feed' => TelematicsFeed::class,
            'last_polled_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_data_at' => 'datetime',
            'paused_until' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_cycle_json' => 'array',
        ];
    }

    protected static function newFactory(): TelematicsFeedCursorFactory
    {
        return TelematicsFeedCursorFactory::new();
    }
}
