<?php

namespace App\Domains\Drivers\Models;

use App\Domains\Drivers\Enums\StatusSeverity;
use Database\Factories\Domains\Drivers\DriverStatusLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverStatusLog extends Model
{
    /** @use HasFactory<DriverStatusLogFactory> */
    use HasFactory;

    protected $table = 'driver_statuses';

    protected $fillable = [
        'driver_id',
        'status_code',
        'status_label',
        'severity',
        'effective_from',
        'effective_to',
        'source_event_id',
        'metadata_json',
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
            'severity' => StatusSeverity::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): DriverStatusLogFactory
    {
        return DriverStatusLogFactory::new();
    }
}
