<?php

namespace App\Domains\Audit\Support;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use Illuminate\Support\Str;

/**
 * Presentation helpers for audit log rows: human (es-MX) labels for action
 * codes, categories and actor types, plus the list of high-volume system
 * actions that the audit pages hide by default ("ruido de sistema").
 */
final class AuditActionPresenter
{
    /**
     * Automated, per-event bookkeeping actions. They are legitimate audit
     * rows but drown out the human decisions an operator is looking for, so
     * the audit pages hide them unless the viewer opts in.
     *
     * @var list<string>
     */
    public const NOISE_ACTIONS = [
        'tenancy.usage_recorded',
        'tenancy.usage_updated',
        'assets.discovered',
        'assets.location_updated',
        'assets.location_updated_broadcast',
        'assets.status_changed_broadcast',
        'normalization.event_normalized',
        'context.event_context_built',
        'ai.evaluation_completed',
        'integration.sync_completed',
    ];

    /**
     * @var array<string, string>
     */
    private const ACTION_LABELS = [
        'tenancy.tenant_created' => 'Tenant creado',
        'tenancy.subscription_updated' => 'Suscripción actualizada',
        'tenancy.subscription_canceled' => 'Suscripción cancelada',
        'tenancy.usage_recorded' => 'Uso registrado',
        'tenancy.usage_limit_exceeded' => 'Límite de uso excedido',
        'tenancy.usage_updated' => 'Uso actualizado',
        'assets.discovered' => 'Activo descubierto',
        'assets.location_updated' => 'Ubicación de activo actualizada',
        'assets.location_updated_broadcast' => 'Ubicación de activo difundida',
        'assets.status_changed' => 'Estado de activo cambiado',
        'assets.status_changed_broadcast' => 'Estado de activo difundido',
        'normalization.event_normalized' => 'Evento normalizado',
        'normalization.event_unmapped' => 'Evento sin mapear',
        'context.event_context_built' => 'Contexto de evento generado',
        'ai.evaluation_completed' => 'Evaluación de IA completada',
        'ai.reevaluation_requested' => 'Reevaluación de IA solicitada',
        'ai.false_positive_detected' => 'Falso positivo detectado por IA',
        'ai.operator_verdict.recorded' => 'Veredicto del operador registrado',
        'ai.quota_warning' => 'Aviso de cuota de IA',
        'auth.login' => 'Inicio de sesión',
        'auth.logout' => 'Cierre de sesión',
        'auth.failed' => 'Intento de inicio de sesión fallido',
        'subscription.renewed' => 'Suscripción renovada',
        'decision.made' => 'Decisión tomada',
        'incident.created' => 'Incidente creado',
        'incident.acknowledged' => 'Incidente reconocido',
        'incident.assigned' => 'Incidente asignado',
        'incident.claimed' => 'Incidente tomado',
        'incident.released' => 'Incidente liberado',
        'incident.escalated' => 'Incidente escalado',
        'incident.resolved' => 'Incidente resuelto',
        'incident.closed' => 'Incidente cerrado',
        'incident.reopened' => 'Incidente reabierto',
        'incident.reclassified' => 'Incidente reclasificado',
        'incident.sla_breached' => 'SLA de incidente vencido',
        'integration.sync_completed' => 'Sincronización de integración completada',
        'integration.status_changed' => 'Estado de integración cambiado',
        'copilot.query' => 'Consulta a SAM Copilot',
        'impersonation.started' => 'Suplantación iniciada',
        'impersonation.stopped' => 'Suplantación finalizada',
        'plan.limits_updated' => 'Límites del plan actualizados',
        'tenant.updated' => 'Tenant actualizado',
        'tenant.deleted' => 'Tenant eliminado',
        'tenant.feature_updated' => 'Función del tenant actualizada',
        'tenant.invoice_paid' => 'Factura marcada como pagada',
        'tenant.invoice_voided' => 'Factura anulada',
        'tenant.member_added' => 'Miembro agregado',
        'tenant.member_removed' => 'Miembro eliminado',
        'tenant.member_role_changed' => 'Rol de miembro cambiado',
        'tenant.owner_reassigned' => 'Propietario reasignado',
        'tenant.plan_changed' => 'Plan cambiado',
        'tenant.subscription_canceled' => 'Suscripción cancelada',
        'tenant.subscription_reactivated' => 'Suscripción reactivada',
        'tenant.subscription_suspended' => 'Suscripción suspendida',
        'tenant.trial_extended' => 'Prueba extendida',
    ];

    public static function actionLabel(?string $action): string
    {
        if ($action === null || $action === '') {
            return '—';
        }

        if (isset(self::ACTION_LABELS[$action])) {
            return self::ACTION_LABELS[$action];
        }

        // Dynamic suffixes such as `incident.call_verification.answered`.
        $segments = explode('.', $action);
        $tail = count($segments) > 1 ? implode(' ', array_slice($segments, 1)) : $action;

        return Str::ucfirst(Str::lower(str_replace('_', ' ', $tail)));
    }

    public static function isNoise(?string $action): bool
    {
        return $action !== null && in_array($action, self::NOISE_ACTIONS, true);
    }

    public static function categoryLabel(?AuditCategory $category): ?string
    {
        return match ($category) {
            AuditCategory::Domain => 'Operación',
            AuditCategory::Security => 'Seguridad',
            AuditCategory::Billing => 'Facturación',
            AuditCategory::System => 'Sistema',
            AuditCategory::Ai => 'IA',
            AuditCategory::Integration => 'Integración',
            null => null,
        };
    }

    public static function actorTypeLabel(?AuditActorType $type): ?string
    {
        return match ($type) {
            AuditActorType::User => 'Usuario',
            AuditActorType::System => 'Sistema',
            AuditActorType::Ai => 'IA',
            AuditActorType::Job => 'Proceso automático',
            AuditActorType::WebhookSource => 'Webhook',
            AuditActorType::Automation => 'Automatización',
            null => null,
        };
    }
}
