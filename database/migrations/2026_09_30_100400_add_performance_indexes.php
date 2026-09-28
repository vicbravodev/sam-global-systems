<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pre-production index audit. PostgreSQL does not index foreign-key columns
 * on its own, so every `foreignId()->constrained()` without an explicit
 * index made both the hot lookups below and the parent's cascade / SET NULL
 * sequential scans of the child table.
 *
 * Additive only. On PostgreSQL each index is built CONCURRENTLY (no write
 * lock on the growing tables), which cannot run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * name => [table, columns, partial WHERE or null]
     *
     * @return array<string, array{0: string, 1: list<string>, 2: string|null}>
     */
    private function indexes(): array
    {
        return [
            // Pipeline: per-event lookups.
            'raw_event_attachments_raw_event_id_index' => ['raw_event_attachments', ['raw_event_id'], null],
            'event_receipts_raw_event_id_index' => ['event_receipts', ['raw_event_id'], null],
            'raw_events_event_source_id_index' => ['raw_events', ['event_source_id'], null],
            'normalized_events_driver_id_occurred_at_index' => ['normalized_events', ['driver_id', 'occurred_at'], null],
            'normalized_events_team_id_status_index' => ['normalized_events', ['team_id', 'status'], null],
            'normalized_events_event_type_id_occurred_at_index' => ['normalized_events', ['event_type_id', 'occurred_at'], null],
            'incidents_related_event_id_index' => ['incidents', ['related_event_id'], null],
            'incident_event_links_normalized_event_id_index' => ['incident_event_links', ['normalized_event_id'], null],
            'asset_external_references_asset_id_index' => ['asset_external_references', ['asset_id'], null],
            'asset_devices_asset_id_index' => ['asset_devices', ['asset_id'], null],
            'asset_devices_external_device_id_index' => ['asset_devices', ['external_device_id'], null],
            'event_media_contexts_asset_id_captured_at_index' => ['event_media_contexts', ['asset_id', 'captured_at'], null],

            // AI quota check (before every evaluation) and usage aggregation.
            'usage_events_team_meter_period_index' => ['usage_events', ['team_id', 'usage_meter_id', 'billing_period_key'], null],
            'usage_events_team_meter_occurred_index' => ['usage_events', ['team_id', 'usage_meter_id', 'occurred_at'], null],

            // Retention / pruning sweeps.
            'asset_telemetry_snapshots_recorded_at_index' => ['asset_telemetry_snapshots', ['recorded_at'], null],
            'event_deduplication_keys_expires_at_index' => ['event_deduplication_keys', ['expires_at'], null],
            'event_deduplication_keys_raw_event_id_index' => ['event_deduplication_keys', ['raw_event_id'], null],
            'event_deduplication_keys_team_id_index' => ['event_deduplication_keys', ['team_id'], null],

            // UI lookups.
            'copilot_messages_conversation_id_index' => ['copilot_messages', ['copilot_conversation_id', 'id'], null],
            'notification_recipients_recipient_index' => ['notification_recipients', ['recipient_type', 'recipient_reference_id'], null],
            'integration_sync_jobs_integration_status_index' => ['integration_sync_jobs', ['tenant_integration_id', 'status'], null],
            'webhook_endpoints_tenant_integration_id_index' => ['webhook_endpoints', ['tenant_integration_id'], null],

            // Tenant tables missing their team_id index (CLAUDE.md §2).
            'driver_assignments_team_id_index' => ['driver_assignments', ['team_id'], null],
            'invoice_snapshots_team_id_period_index' => ['invoice_snapshots', ['team_id', 'period_start', 'period_end'], null],
            'team_invitations_team_id_index' => ['team_invitations', ['team_id'], null],
            'user_preferences_team_id_index' => ['user_preferences', ['team_id'], null],

            // User FKs that are SET NULL on delete: a user delete otherwise
            // scans each of these tables.
            'usage_events_user_id_index' => ['usage_events', ['user_id'], 'user_id IS NOT NULL'],
            'incidents_acknowledged_by_index' => ['incidents', ['acknowledged_by'], 'acknowledged_by IS NOT NULL'],
            'incidents_claimed_by_user_id_index' => ['incidents', ['claimed_by_user_id'], 'claimed_by_user_id IS NOT NULL'],
            'ai_event_evaluations_operator_verdict_by_index' => ['ai_event_evaluations', ['operator_verdict_by'], 'operator_verdict_by IS NOT NULL'],
            'copilot_messages_user_id_index' => ['copilot_messages', ['user_id'], 'user_id IS NOT NULL'],
            'notification_reply_tokens_user_id_index' => ['notification_reply_tokens', ['user_id'], 'user_id IS NOT NULL'],
            'notification_preferences_user_id_index' => ['notification_preferences', ['user_id'], 'user_id IS NOT NULL'],
        ];
    }

    public function up(): void
    {
        $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';

        foreach ($this->indexes() as $name => [$table, $columns, $where]) {
            $columnList = implode(', ', array_map(fn (string $column) => '"'.$column.'"', $columns));

            DB::statement(sprintf(
                'CREATE INDEX %sIF NOT EXISTS "%s" ON "%s" (%s)%s',
                $concurrently,
                $name,
                $table,
                $columnList,
                $where !== null ? ' WHERE '.$where : '',
            ));
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes()) as $name) {
            DB::statement(sprintf('DROP INDEX IF EXISTS "%s"', $name));
        }
    }
};
