<?php

namespace App\Domains\Drivers\Models;

use App\Domains\Integrations\Models\IntegrationProvider;
use Database\Factories\Domains\Drivers\DriverExternalReferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverExternalReference extends Model
{
    /** @use HasFactory<DriverExternalReferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'provider_id',
        'external_id',
        'external_type',
        'metadata_json',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<IntegrationProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IntegrationProvider::class, 'provider_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DriverExternalReferenceFactory
    {
        return DriverExternalReferenceFactory::new();
    }
}
