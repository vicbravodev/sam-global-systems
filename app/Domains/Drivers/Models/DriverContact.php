<?php

namespace App\Domains\Drivers\Models;

use App\Domains\Drivers\Enums\ContactType;
use Database\Factories\Domains\Drivers\DriverContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverContact extends Model
{
    /** @use HasFactory<DriverContactFactory> */
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'contact_type',
        'label',
        'value',
        'is_primary',
        'is_emergency',
        'verified_at',
    ];

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contact_type' => ContactType::class,
            'is_primary' => 'boolean',
            'is_emergency' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DriverContactFactory
    {
        return DriverContactFactory::new();
    }
}
