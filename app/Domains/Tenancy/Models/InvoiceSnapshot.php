<?php

namespace App\Domains\Tenancy\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Enums\InvoiceStatus;
use Database\Factories\Domains\Tenancy\InvoiceSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSnapshot extends Model
{
    /** @use HasFactory<InvoiceSnapshotFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'subscription_id',
        'period_start',
        'period_end',
        'subtotal',
        'overage_total',
        'total',
        'currency',
        'status',
        'breakdown_json',
        'generated_at',
        'payment_receipt_file_object_id',
        'paid_at',
        'payment_note',
    ];

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'subtotal' => 'decimal:2',
            'overage_total' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'breakdown_json' => 'array',
            'generated_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FileObject, $this>
     */
    public function paymentReceipt(): BelongsTo
    {
        return $this->belongsTo(FileObject::class, 'payment_receipt_file_object_id');
    }

    protected static function newFactory(): InvoiceSnapshotFactory
    {
        return InvoiceSnapshotFactory::new();
    }
}
