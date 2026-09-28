<?php

namespace App\Domains\Tenancy\Listeners;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Models\Team;

/**
 * Deja rastro cuando un tenant rebasa lo incluido en un medidor. Antes el
 * evento se emitía y nadie lo escuchaba: ni el operador ni el cliente se
 * enteraban. Con tope suave (2026-09-28) el rebase se cobra como extra, así
 * que tiene que quedar en la auditoría de facturación.
 */
class AuditUsageLimitExceeded
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function handle(UsageLimitExceeded $event): void
    {
        $this->audit->execute(
            actorType: AuditActorType::System,
            actorId: null,
            action: 'usage.limit_exceeded',
            category: AuditCategory::Billing,
            entityType: Team::class,
            entityId: $event->teamId,
            summary: sprintf(
                'Medidor %s por encima de lo incluido: %d de %d.',
                $event->meterCode,
                $event->consumed,
                $event->included,
            ),
            teamId: $event->teamId,
            metadata: [
                'meter_code' => $event->meterCode,
                'consumed' => $event->consumed,
                'included' => $event->included,
            ],
            signature: "usage.limit_exceeded:{$event->teamId}:{$event->meterCode}:".now()->format('Y-m-d'),
        );
    }
}
