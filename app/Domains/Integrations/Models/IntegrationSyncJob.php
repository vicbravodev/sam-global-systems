<?php

namespace App\Domains\Integrations\Models;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use Database\Factories\Domains\Integrations\IntegrationSyncJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncJob extends Model
{
    /** @use HasFactory<IntegrationSyncJobFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_integration_id',
        'type',
        'status',
        'started_at',
        'finished_at',
        'records_processed',
        'error_message',
    ];

    /**
     * @return BelongsTo<TenantIntegration, $this>
     */
    public function tenantIntegration(): BelongsTo
    {
        return $this->belongsTo(TenantIntegration::class);
    }

    public function markAsRunning(): void
    {
        $this->update([
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(int $recordsProcessed): void
    {
        $this->update([
            'status' => SyncStatus::Completed,
            'finished_at' => now(),
            'records_processed' => $recordsProcessed,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => SyncStatus::Failed,
            'finished_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SyncType::class,
            'status' => SyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'records_processed' => 'integer',
        ];
    }

    protected static function newFactory(): IntegrationSyncJobFactory
    {
        return IntegrationSyncJobFactory::new();
    }
}
