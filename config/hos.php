<?php

/*
| Monitoreo HOS (EE. UU.) — spec docs/superpowers/specs/2026-10-04-hos-monitoring-design.md.
| `defaults` es la configuración que se usa cuando el tenant no guardó la suya
| (TenantSetting `hos.monitoring`, que se mezcla encima clave por clave).
*/

return [
    'tags_cache_seconds' => (int) env('HOS_TAGS_CACHE_SECONDS', 300),

    'defaults' => [
        'tag_ids' => [],
        'included_asset_ids' => [],
        'excluded_asset_ids' => [],
        'situations' => [
            'break_due' => true,
            'drive_limit' => true,
            'shift_limit' => true,
            'cycle_limit' => true,
            'rest_complete' => true,
        ],
        'lead_minutes' => [30, 15, 0],
        'cycle_lead_hours' => [5, 1],
        'rest_complete_nudge_minutes' => [15, 30],
        'rest_complete_expire_minutes' => 35,
        'ladder' => [
            ['after_minutes' => 0, 'channels' => ['samsara_driver_app']],
            ['after_minutes' => 5, 'channels' => ['samsara_driver_app', 'whatsapp']],
            ['after_minutes' => 10, 'channels' => ['voice']],
            ['after_minutes' => 15, 'escalate' => 'incident'],
        ],
    ],
];
