<?php

namespace Database\Seeders;

use App\Domains\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Global (team_id = null) notification templates. Tenants can override any of
 * them by creating an active template with the same event_type/channel_type
 * — RenderNotificationContent prefers team-scoped templates over globals.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'code' => 'incident-panic-created-email',
                'name' => 'Panic incident created (email)',
                'channel_type' => 'email',
                'event_type' => 'incident.panic_emergency.created',
                'subject_template' => '🚨 PÁNICO: {{ $incident_title }}',
                'body_template' => implode("\n", [
                    'Se activó un botón de pánico.',
                    '',
                    'Unidad: {{ $asset_name }}',
                    'Conductor: {{ $driver_name }}',
                    'Ubicación: {{ $location }}',
                    'Media disponible: {{ $has_media }}',
                    '',
                    'Atender ahora: {{ $incident_url }}',
                ]),
            ],
            [
                'code' => 'incident-panic-created-web',
                'name' => 'Panic incident created (web)',
                'channel_type' => 'web',
                'event_type' => 'incident.panic_emergency.created',
                'subject_template' => '🚨 PÁNICO: {{ $incident_title }}',
                'body_template' => 'Unidad {{ $asset_name }} · {{ $driver_name }} — atender ahora: {{ $incident_url }}',
            ],
        ];

        foreach ($templates as $template) {
            NotificationTemplate::withoutGlobalScopes()->updateOrCreate(
                [
                    'team_id' => null,
                    'channel_type' => $template['channel_type'],
                    'event_type' => $template['event_type'],
                ],
                [
                    'code' => $template['code'],
                    'name' => $template['name'],
                    'subject_template' => $template['subject_template'],
                    'body_template' => $template['body_template'],
                    'priority' => 'critical',
                    'locale' => 'es',
                    'is_active' => true,
                ],
            );
        }
    }
}
