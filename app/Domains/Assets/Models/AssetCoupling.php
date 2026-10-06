<?php

namespace App\Domains\Assets\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Actions\EvaluateTrailerCouplings;
use App\Domains\Assets\Enums\CouplingDecoupleReason;
use App\Domains\Assets\Enums\CouplingSource;
use Database\Factories\Domains\Assets\AssetCouplingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un tramo en que un remolque fue arrastrado por un tracto. Abierto mientras
 * `decoupled_at` es null; lo abre y cierra {@see EvaluateTrailerCouplings}.
 */
class AssetCoupling extends Model
{
    /** @use HasFactory<AssetCouplingFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'tractor_asset_id',
        'trailer_asset_id',
        'source',
        'coupled_at',
        'last_confirmed_at',
        'decoupled_at',
        'decouple_reason',
        'evidence_json',
    ];

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function tractor(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'tractor_asset_id');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function trailer(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'trailer_asset_id');
    }

    /**
     * @param  Builder<AssetCoupling>  $query
     * @return Builder<AssetCoupling>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('decoupled_at');
    }

    /**
     * Tramos vigentes en un instante: enganchados antes y no soltados aún.
     *
     * @param  Builder<AssetCoupling>  $query
     * @return Builder<AssetCoupling>
     */
    public function scopeActiveAt(Builder $query, \DateTimeInterface $at): Builder
    {
        return $query
            ->where('coupled_at', '<=', $at)
            ->where(fn (Builder $open) => $open->whereNull('decoupled_at')->orWhere('decoupled_at', '>', $at));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => CouplingSource::class,
            'decouple_reason' => CouplingDecoupleReason::class,
            'coupled_at' => 'datetime',
            'last_confirmed_at' => 'datetime',
            'decoupled_at' => 'datetime',
            'evidence_json' => 'array',
        ];
    }

    protected static function newFactory(): AssetCouplingFactory
    {
        return AssetCouplingFactory::new();
    }
}
