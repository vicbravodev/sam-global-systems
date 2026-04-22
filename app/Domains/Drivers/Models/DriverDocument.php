<?php

namespace App\Domains\Drivers\Models;

use App\Domains\Drivers\Enums\DocumentStatus;
use App\Domains\Drivers\Enums\DocumentType;
use Database\Factories\Domains\Drivers\DriverDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDocument extends Model
{
    /** @use HasFactory<DriverDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'document_type',
        'document_number',
        'issued_at',
        'expires_at',
        'status',
        'file_url',
        'metadata_json',
    ];

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'metadata_json' => 'array',
        ];
    }

    protected static function newFactory(): DriverDocumentFactory
    {
        return DriverDocumentFactory::new();
    }
}
