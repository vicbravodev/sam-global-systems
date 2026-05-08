import { Head } from '@inertiajs/react';
import {
    Filter,
    Inbox,
    LayoutList,
    RefreshCw,
    Rows3,
    Search,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { InboxGrouped } from '@/components/sam/inbox/inbox-grouped';
import { InboxStream } from '@/components/sam/inbox/inbox-stream';
import { InboxTable } from '@/components/sam/inbox/inbox-table';
import { IncidentDetailPanel } from '@/components/sam/incident-detail';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type {
    InboxDensity,
    InboxLayout,
    InboxTab,
    IncidentDetail,
} from '@/types/sam';

// ---- Mock data ----

const INCIDENTS: IncidentDetail[] = [
    {
        id: 'INC-2026-04850',
        title: 'Colisión frontal detectada',
        severity: 'critical',
        status: 'in-progress',
        provider: 'Samsara',
        asset: 'T-412 · Volvo FH16',
        driver: 'M. Pereira',
        assignee: { name: 'María Gómez', initials: 'MG' },
        slaSeconds: 252,
        slaTotal: 1800,
        ageMin: 2,
        eventType: 'collision',
        location: 'RN7 km 184 · Mendoza',
        aiConfidence: 0.87,
        aiDecision: 'incident',
        aiReason:
            'Aceleración lateral 1.8 g, caída instantánea 68→0 km/h, sin frenado previo. Patrón coincide con 94 % de colisiones confirmadas en los últimos 30 días.',
        realtime: true,
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 320,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'collision · firma válida',
                ts: '14:32:07',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '87 % confianza · 320 ms',
                ts: '14:32:08',
            },
            {
                type: 'critical',
                actor: 'Sistema',
                text: 'incidente creado — critical',
                sub: 'prioridad P1 por regla auto_escalate_collision',
                ts: '14:32:08',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a María Gómez',
                sub: 'regla "colisión → supervisor de turno"',
                ts: '14:32:09',
            },
            {
                type: 'comment',
                actor: 'M. Gómez',
                text: 'añadió nota interna',
                sub: 'Llamé al conductor, reporta estar bien. Despaché asistencia mecánica.',
                ts: '14:34:12',
            },
        ],
        relatedLinks: [
            {
                ts: '14:31:54',
                eventType: 'harsh_accel',
                asset: 'T-412',
                decision: 'discard',
                severity: null,
            },
            {
                ts: '14:29:12',
                eventType: 'overspeed',
                asset: 'T-412',
                decision: 'info',
                severity: 'medium',
            },
            {
                ts: '13:58:41',
                eventType: 'harsh_brake',
                asset: 'T-412',
                decision: 'incident',
                severity: 'high',
            },
        ],
        comments: [
            {
                authorInitials: 'MG',
                authorName: 'María Gómez',
                visibility: 'internal',
                body: 'Llamé al conductor, reporta estar bien. Despaché asistencia mecánica y notifiqué a operaciones. Espero confirmación en 10 min.',
                relativeTime: 'hace 2 min',
            },
            {
                authorInitials: 'JR',
                authorName: 'J. Ríos',
                visibility: 'tenant',
                body: 'Notificado al cliente Andes Logística. Esperando respuesta del supervisor de ruta.',
                relativeTime: 'hace 1 min',
            },
        ],
        evidence: [
            {
                label: 'Snapshot telemetría',
                sub: 't=−4s a t+4s',
                type: 'chart',
            },
            { label: 'Video cabina', sub: '8 s · 720p', type: 'video' },
            {
                label: 'Mapa del evento',
                sub: '−34.60, −68.81',
                type: 'map',
            },
            {
                label: 'Payload webhook',
                sub: '4.2 KB · JSON',
                type: 'payload',
            },
        ],
        operationalContext: {
            weather: 'Lluvia leve · 14 °C',
            traffic: 'Moderado',
            driverRisk: 58,
            geofenceStatus: 'Fuera (ruta RN7)',
            drivingHours: '4h 12m / 9h max',
        },
    },
    {
        id: 'INC-2026-04849',
        title: 'Botón de pánico presionado',
        severity: 'critical',
        status: 'new',
        provider: 'Motive',
        asset: 'T-744 · Volvo VM270',
        driver: 'N. Paredes',
        assignee: null,
        slaSeconds: 94,
        slaTotal: 1800,
        ageMin: 1,
        eventType: 'panic_button',
        location: 'Av. 25 de Mayo · Mar del Plata',
        aiConfidence: 0.93,
        aiDecision: 'escalate',
        realtime: true,
        aiReason:
            'Botón de pánico activado por el conductor. Llamada al número de emergencia del tenant ya disparada.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 280,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Motive',
                sub: 'panic_button · firma válida',
                ts: '14:33:01',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — escalate',
                sub: '93 % confianza · 280 ms',
                ts: '14:33:01',
            },
            {
                type: 'critical',
                actor: 'Sistema',
                text: 'incidente creado — critical',
                sub: 'escalamiento automático activado',
                ts: '14:33:02',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [
            {
                label: 'Payload webhook',
                sub: '1.8 KB · JSON',
                type: 'payload',
            },
        ],
        operationalContext: {
            weather: 'Despejado · 22 °C',
            traffic: 'Bajo',
            driverRisk: 72,
            geofenceStatus: 'Dentro',
            drivingHours: '6h 30m / 9h max',
        },
    },
    {
        id: 'INC-2026-04848',
        title: 'Frenado brusco > 0.6 g',
        severity: 'high',
        status: 'new',
        provider: 'Motive',
        asset: 'T-118 · Scania R450',
        driver: 'L. Silva',
        assignee: null,
        slaSeconds: 728,
        slaTotal: 1800,
        ageMin: 5,
        eventType: 'harsh_brake',
        location: 'Av. Perón 2200 · CABA',
        aiConfidence: 0.71,
        aiDecision: 'incident',
        realtime: true,
        aiReason:
            'Desaceleración 0.62 g en zona urbana. Tres eventos similares en la misma jornada del mismo conductor.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 410,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Motive',
                sub: 'harsh_brake · firma válida',
                ts: '14:29:41',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '71 % confianza · 410 ms',
                ts: '14:29:41',
            },
            {
                type: 'critical',
                actor: 'Sistema',
                text: 'incidente creado — high',
                sub: 'sin asignar',
                ts: '14:29:42',
            },
        ],
        relatedLinks: [
            {
                ts: '13:15:22',
                eventType: 'harsh_brake',
                asset: 'T-118',
                decision: 'incident',
                severity: 'high',
            },
        ],
        comments: [],
        evidence: [
            {
                label: 'Snapshot telemetría',
                sub: 't=−2s a t+2s',
                type: 'chart',
            },
        ],
        operationalContext: {
            weather: 'Llovizna · 16 °C',
            traffic: 'Alto',
            driverRisk: 45,
            geofenceStatus: 'Dentro',
            drivingHours: '3h 45m / 9h max',
        },
    },
    {
        id: 'INC-2026-04847',
        title: 'Posible fatiga del conductor',
        severity: 'high',
        status: 'assigned',
        provider: 'Motive',
        asset: 'T-502 · Volvo FMX',
        driver: 'F. Medina',
        assignee: { name: 'María Gómez', initials: 'MG' },
        slaSeconds: 420,
        slaTotal: 1800,
        ageMin: 48,
        eventType: 'fatigue',
        location: 'RN9 km 412 · Córdoba',
        aiConfidence: 0.79,
        aiDecision: 'escalate',
        aiReason:
            '2 microsueños detectados en 8 min + 11 h continuas de conducción (excede normativa).',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 380,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Motive',
                sub: 'fatigue · firma válida',
                ts: '06:44:19',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — escalate',
                sub: '79 % confianza',
                ts: '06:44:20',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a María Gómez',
                ts: '06:44:21',
            },
        ],
        relatedLinks: [],
        comments: [
            {
                authorInitials: 'MG',
                authorName: 'María Gómez',
                visibility: 'internal',
                body: 'Contacté al coordinador de ruta. El conductor fue detenido en área de descanso.',
                relativeTime: 'hace 40 min',
            },
        ],
        evidence: [
            { label: 'Video cabina', sub: '12 s · 720p', type: 'video' },
        ],
        operationalContext: {
            weather: 'Despejado · 12 °C',
            traffic: 'Bajo',
            driverRisk: 82,
            geofenceStatus: 'Dentro (ruta RN9)',
            drivingHours: '11h 05m / 9h max',
        },
    },
    {
        id: 'INC-2026-04846',
        title: 'Posible volcadura detectada',
        severity: 'critical',
        status: 'triaging',
        provider: 'Samsara',
        asset: 'T-466 · Volvo FH440',
        driver: 'O. Miranda',
        assignee: { name: 'J. Ríos', initials: 'JR' },
        slaSeconds: -180,
        slaTotal: 1800,
        ageMin: 33,
        eventType: 'rollover',
        location: 'RN40 km 1544 · San Juan',
        aiConfidence: 0.88,
        aiDecision: 'incident',
        aiReason:
            'Telemetría de inclinación > 42° durante 4 s. Sensor G lateral: 2.3 g. ECU reportó apagado tras el evento.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 295,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'rollover · firma válida',
                ts: '14:01:05',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '88 % confianza',
                ts: '14:01:06',
            },
            {
                type: 'critical',
                actor: 'Sistema',
                text: 'incidente creado — critical',
                ts: '14:01:06',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a J. Ríos',
                ts: '14:01:07',
            },
        ],
        relatedLinks: [],
        comments: [
            {
                authorInitials: 'JR',
                authorName: 'J. Ríos',
                visibility: 'internal',
                body: 'Sin contacto con el conductor. Despachando emergencias al km 1544.',
                relativeTime: 'hace 28 min',
            },
        ],
        evidence: [
            {
                label: 'Snapshot telemetría',
                sub: 'volcadura · 4 s',
                type: 'chart',
            },
            {
                label: 'Mapa del evento',
                sub: '−31.52, −68.53',
                type: 'map',
            },
        ],
        operationalContext: {
            weather: 'Viento fuerte · 8 °C',
            traffic: 'Nulo',
            driverRisk: 91,
            geofenceStatus: 'Fuera',
            drivingHours: '9h 33m / 9h max',
        },
    },
    {
        id: 'INC-2026-04845',
        title: 'Exceso de velocidad sostenido',
        severity: 'medium',
        status: 'assigned',
        provider: 'Samsara',
        asset: 'T-207 · MB Actros',
        driver: 'C. Ruiz',
        assignee: { name: 'J. Ríos', initials: 'JR' },
        slaSeconds: 1721,
        slaTotal: 3600,
        ageMin: 22,
        eventType: 'overspeed',
        location: 'Ruta 2 km 64 · La Plata',
        aiConfidence: 0.82,
        aiDecision: 'incident',
        aiReason:
            'Exceso sostenido de 18 min en tramo con límite 70. Condición meteorológica: lluvia.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 440,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'overspeed · firma válida',
                ts: '14:12:22',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '82 % confianza',
                ts: '14:12:23',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a J. Ríos',
                ts: '14:12:24',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [
            {
                label: 'Snapshot telemetría',
                sub: '18 min · 92 km/h',
                type: 'chart',
            },
        ],
        operationalContext: {
            weather: 'Lluvia moderada · 15 °C',
            traffic: 'Moderado',
            driverRisk: 38,
            geofenceStatus: 'Dentro',
            drivingHours: '2h 10m / 9h max',
        },
    },
    {
        id: 'INC-2026-04844',
        title: 'Sin cinturón detectado',
        severity: 'low',
        status: 'triaging',
        provider: 'Samsara',
        asset: 'T-301 · Iveco S-Way',
        driver: 'D. Acosta',
        assignee: { name: 'J. Ríos', initials: 'JR' },
        slaSeconds: 2400,
        slaTotal: 3600,
        ageMin: 41,
        eventType: 'no_seatbelt',
        location: 'Depósito central · Rosario',
        aiConfidence: 0.55,
        aiDecision: 'incident',
        aiReason:
            'Sensor de cinturón 28 s sin detectar tensión. Vehículo en movimiento a 45 km/h.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 510,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'no_seatbelt · firma válida',
                ts: '13:53:01',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '55 % confianza',
                ts: '13:53:02',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a J. Ríos',
                ts: '13:53:03',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [],
        operationalContext: {
            weather: 'Nublado · 18 °C',
            traffic: 'Bajo',
            driverRisk: 22,
            geofenceStatus: 'Dentro',
            drivingHours: '1h 45m / 9h max',
        },
    },
    {
        id: 'INC-2026-04843',
        title: 'Caída anómala de combustible',
        severity: 'high',
        status: 'new',
        provider: 'Samsara',
        asset: 'T-055 · Ford Cargo',
        driver: 'R. Vera',
        assignee: null,
        slaSeconds: 612,
        slaTotal: 1800,
        ageMin: 8,
        eventType: 'fuel_drop',
        location: 'Playa de maniobras · Tigre',
        aiConfidence: 0.74,
        aiDecision: 'incident',
        aiReason:
            'Caída de 38 L en sensor de tanque durante 4 min — vehículo detenido sin carga programada.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 360,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'fuel_drop · firma válida',
                ts: '14:26:14',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '74 % confianza',
                ts: '14:26:15',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [
            {
                label: 'Snapshot telemetría',
                sub: 'combustible 4 min',
                type: 'chart',
            },
        ],
        operationalContext: {
            weather: 'Despejado · 24 °C',
            traffic: 'Bajo',
            driverRisk: 30,
            geofenceStatus: 'Dentro',
            drivingHours: '1h 20m / 9h max',
        },
    },
    {
        id: 'INC-2026-04842',
        title: 'Uso de teléfono en marcha',
        severity: 'medium',
        status: 'assigned',
        provider: 'Samsara',
        asset: 'T-088 · Kenworth T680',
        driver: 'J. Ortiz',
        assignee: { name: 'P. Correa', initials: 'PC' },
        slaSeconds: 2100,
        slaTotal: 3600,
        ageMin: 18,
        eventType: 'phone_use',
        location: 'Sector Norte · Ezeiza',
        aiConfidence: 0.68,
        aiDecision: 'incident',
        aiReason:
            'Detección por cámara cabina: teléfono en mano del conductor durante 14 s.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 480,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'phone_use · firma válida',
                ts: '14:16:05',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '68 % confianza',
                ts: '14:16:06',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a P. Correa',
                ts: '14:16:07',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [
            { label: 'Video cabina', sub: '14 s · 1080p', type: 'video' },
        ],
        operationalContext: {
            weather: 'Despejado · 21 °C',
            traffic: 'Moderado',
            driverRisk: 35,
            geofenceStatus: 'Dentro',
            drivingHours: '4h 55m / 9h max',
        },
    },
    {
        id: 'INC-2026-04841',
        title: 'Aceleración brusca > 0.5 g',
        severity: 'high',
        status: 'in-progress',
        provider: 'Geotab',
        asset: 'T-629 · MB Axor',
        driver: 'E. Molina',
        assignee: { name: 'L. Méndez', initials: 'LM' },
        slaSeconds: 842,
        slaTotal: 1800,
        ageMin: 15,
        eventType: 'harsh_accel',
        location: 'RN34 km 188 · Tucumán',
        aiConfidence: 0.77,
        aiDecision: 'incident',
        aiReason:
            'Aceleración longitudinal 0.58 g detectada por IMU. Segundo evento del turno para el mismo conductor.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 390,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Geotab',
                sub: 'harsh_accel · firma válida',
                ts: '14:19:18',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '77 % confianza',
                ts: '14:19:19',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a L. Méndez',
                ts: '14:19:20',
            },
        ],
        relatedLinks: [
            {
                ts: '11:33:44',
                eventType: 'harsh_accel',
                asset: 'T-629',
                decision: 'incident',
                severity: 'high',
            },
        ],
        comments: [],
        evidence: [
            {
                label: 'Snapshot telemetría',
                sub: 'IMU · 0.58 g',
                type: 'chart',
            },
        ],
        operationalContext: {
            weather: 'Parcialmente nublado · 19 °C',
            traffic: 'Bajo',
            driverRisk: 48,
            geofenceStatus: 'Dentro',
            drivingHours: '5h 12m / 9h max',
        },
    },
    {
        id: 'INC-2026-04840',
        title: 'Falla de motor reportada por ECU',
        severity: 'high',
        status: 'new',
        provider: 'Samsara',
        asset: 'T-150 · Iveco Tector',
        driver: 'H. Sosa',
        assignee: null,
        slaSeconds: 1040,
        slaTotal: 1800,
        ageMin: 12,
        eventType: 'engine_fault',
        location: 'Av. Colón · Córdoba',
        aiConfidence: 0.69,
        aiDecision: 'incident',
        aiReason:
            'Código DTC P0299 — sobrepresión turbo. ECU redujo potencia automáticamente.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 445,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'engine_fault · firma válida',
                ts: '14:22:08',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '69 % confianza',
                ts: '14:22:09',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [
            {
                label: 'Payload webhook',
                sub: 'DTC P0299 · JSON',
                type: 'payload',
            },
        ],
        operationalContext: {
            weather: 'Despejado · 20 °C',
            traffic: 'Moderado',
            driverRisk: 25,
            geofenceStatus: 'Dentro',
            drivingHours: '3h 30m / 9h max',
        },
    },
    {
        id: 'INC-2026-04839',
        title: 'Salida de geocerca no autorizada',
        severity: 'medium',
        status: 'triaging',
        provider: 'Samsara',
        asset: 'T-217 · Scania G410',
        driver: 'S. Bravo',
        assignee: { name: 'S. Quiroga', initials: 'SQ' },
        slaSeconds: 2840,
        slaTotal: 3600,
        ageMin: 28,
        eventType: 'geofence_exit',
        location: 'Terminal Pilar',
        aiConfidence: 0.52,
        aiDecision: 'info',
        aiReason:
            'Salida de geocerca del cliente 4412 sin ruta asignada. Histórico: salidas similares del mismo activo los jueves.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 520,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'geofence_exit · firma válida',
                ts: '14:06:11',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — info',
                sub: '52 % confianza',
                ts: '14:06:12',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [
            {
                label: 'Mapa del evento',
                sub: 'Terminal Pilar',
                type: 'map',
            },
        ],
        operationalContext: {
            weather: 'Despejado · 23 °C',
            traffic: 'Bajo',
            driverRisk: 18,
            geofenceStatus: 'Fuera (cliente 4412)',
            drivingHours: '2h 45m / 9h max',
        },
    },
    {
        id: 'INC-2026-04838',
        title: 'Distancia insegura al vehículo previo',
        severity: 'medium',
        status: 'assigned',
        provider: 'Motive',
        asset: 'T-933 · Scania P310',
        driver: 'V. Aguirre',
        assignee: { name: 'María Gómez', initials: 'MG' },
        slaSeconds: 1902,
        slaTotal: 3600,
        ageMin: 34,
        eventType: 'tailgating',
        location: 'RN8 km 212 · Venado Tuerto',
        aiConfidence: 0.71,
        aiDecision: 'incident',
        aiReason:
            'Seguimiento < 1 s al vehículo previo durante 22 s a 82 km/h en ruta nacional.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 415,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Motive',
                sub: 'tailgating · firma válida',
                ts: '13:59:55',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '71 % confianza',
                ts: '13:59:56',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a María Gómez',
                ts: '13:59:57',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [],
        operationalContext: {
            weather: 'Despejado · 22 °C',
            traffic: 'Moderado',
            driverRisk: 40,
            geofenceStatus: 'Dentro',
            drivingHours: '6h 05m / 9h max',
        },
    },
    {
        id: 'INC-2026-04837',
        title: 'Ralentí prolongado > 30 min',
        severity: 'low',
        status: 'assigned',
        provider: 'Geotab',
        asset: 'T-042 · Ford Cargo',
        driver: 'R. Vera',
        assignee: { name: 'J. Ríos', initials: 'JR' },
        slaSeconds: 3200,
        slaTotal: 3600,
        ageMin: 62,
        eventType: 'idle',
        location: 'Playa de maniobras · Tigre',
        aiConfidence: 0.63,
        aiDecision: 'info',
        aiReason:
            '42 min de ralentí — patrón repetido en este activo esta semana.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 380,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Geotab',
                sub: 'idle · firma válida',
                ts: '13:32:14',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — info',
                sub: '63 % confianza',
                ts: '13:32:15',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a J. Ríos',
                ts: '13:32:16',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [],
        operationalContext: {
            weather: 'Nublado · 17 °C',
            traffic: 'Bajo',
            driverRisk: 12,
            geofenceStatus: 'Dentro',
            drivingHours: '3h 20m / 9h max',
        },
    },
    {
        id: 'INC-2026-04836',
        title: 'Frenado brusco > 0.7 g',
        severity: 'high',
        status: 'in-progress',
        provider: 'Samsara',
        asset: 'T-361 · Kenworth T800',
        driver: 'Z. Cabrera',
        assignee: { name: 'María Gómez', initials: 'MG' },
        slaSeconds: 980,
        slaTotal: 1800,
        ageMin: 25,
        eventType: 'harsh_brake',
        location: 'RN3 km 92 · Azul',
        aiConfidence: 0.82,
        aiDecision: 'incident',
        aiReason:
            'Frenado 0.71 g precedido por sobrepaso a vehículo particular. Cámara frontal capturó el evento.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 360,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'harsh_brake · firma válida',
                ts: '14:09:31',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — incident',
                sub: '82 % confianza',
                ts: '14:09:32',
            },
            {
                type: 'assign',
                actor: 'Sistema',
                text: 'asignado a María Gómez',
                ts: '14:09:33',
            },
        ],
        relatedLinks: [],
        comments: [
            {
                authorInitials: 'MG',
                authorName: 'María Gómez',
                visibility: 'internal',
                body: 'Conductor reportó invasión de carril por tercero. Revisando video.',
                relativeTime: 'hace 20 min',
            },
        ],
        evidence: [
            { label: 'Video cabina', sub: '6 s · 720p', type: 'video' },
            {
                label: 'Snapshot telemetría',
                sub: '0.71 g',
                type: 'chart',
            },
        ],
        operationalContext: {
            weather: 'Lluvioso · 11 °C',
            traffic: 'Moderado',
            driverRisk: 42,
            geofenceStatus: 'Dentro',
            drivingHours: '4h 40m / 9h max',
        },
    },
    {
        id: 'INC-2026-04835',
        title: 'Ralentí prolongado > 30 min',
        severity: 'low',
        status: 'resolved',
        provider: 'Geotab',
        asset: 'T-042 · Ford Cargo',
        driver: 'R. Vera',
        assignee: { name: 'J. Ríos', initials: 'JR' },
        slaSeconds: 0,
        slaTotal: 3600,
        ageMin: 96,
        eventType: 'idle',
        location: 'Playa de maniobras · Tigre',
        aiConfidence: 0.63,
        aiDecision: 'info',
        aiReason: '42 min de ralentí — resuelto sin acción.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 340,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Geotab',
                ts: '06:30:00',
            },
            {
                type: 'system',
                actor: 'Sistema',
                text: 'resuelto automáticamente',
                ts: '07:15:00',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [],
        operationalContext: {
            weather: 'Despejado · 14 °C',
            traffic: 'Bajo',
            driverRisk: 10,
            geofenceStatus: 'Dentro',
            drivingHours: '2h 10m / 9h max',
        },
    },
    {
        id: 'INC-2026-04834',
        title: 'Evento duplicado descartado',
        severity: 'info',
        status: 'discarded',
        provider: 'Samsara',
        asset: 'T-412 · Volvo FH16',
        driver: 'M. Pereira',
        assignee: null,
        slaSeconds: 0,
        slaTotal: 3600,
        ageMin: 140,
        eventType: 'duplicate',
        location: 'RN7 km 184 · Mendoza',
        aiConfidence: 0.94,
        aiDecision: 'discard',
        aiReason:
            'Webhook duplicado del mismo evento (INC-2026-04814) recibido 4 s después. Firma coincide.',
        model: 'claude-sonnet-4-6 · v1.2',
        latencyMs: 180,
        timeline: [
            {
                type: 'webhook',
                actor: 'Webhook',
                text: 'recibido — Samsara',
                sub: 'duplicado detectado',
                ts: '12:02:44',
            },
            {
                type: 'ai',
                actor: 'SAM',
                text: 'evaluó — discard',
                sub: '94 % confianza',
                ts: '12:02:44',
            },
        ],
        relatedLinks: [],
        comments: [],
        evidence: [],
        operationalContext: {
            weather: 'Lluvia leve · 14 °C',
            traffic: 'Moderado',
            driverRisk: 58,
            geofenceStatus: 'Fuera (ruta RN7)',
            drivingHours: '4h 12m / 9h max',
        },
    },
];

// ---- BulkBar ----

function BulkBar({ count, onClear }: { count: number; onClear: () => void }) {
    return (
        <div className="flex shrink-0 items-center gap-2.5 border-b border-border bg-primary/18 px-5 py-2">
            <span className="text-[12px] font-semibold text-primary">
                {count} seleccionados
            </span>
            <Button size="sm" variant="outline">
                Asignarme
            </Button>
            <Button size="sm" variant="outline">
                Escalar
            </Button>
            <Button size="sm" variant="outline">
                Descartar
            </Button>
            <Button
                size="sm"
                variant="ghost"
                onClick={onClear}
                className="ml-auto"
            >
                Deseleccionar
            </Button>
        </div>
    );
}

// ---- PageHead ----

interface PageHeadProps {
    openCount: number;
    criticalCount: number;
    layout: InboxLayout;
    setLayout: (l: InboxLayout) => void;
}

function PageHead({
    openCount,
    criticalCount,
    layout,
    setLayout,
}: PageHeadProps) {
    const layouts: {
        value: InboxLayout;
        icon: React.ReactNode;
        label: string;
    }[] = [
        {
            value: 'table',
            icon: <LayoutList size={14} strokeWidth={1.75} />,
            label: 'Tabla',
        },
        {
            value: 'grouped',
            icon: <Rows3 size={14} strokeWidth={1.75} />,
            label: 'Agrupado',
        },
        {
            value: 'stream',
            icon: <Inbox size={14} strokeWidth={1.75} />,
            label: 'Stream',
        },
    ];

    return (
        <header className="flex shrink-0 items-center justify-between gap-3 border-b border-border bg-surface-1 px-5 py-3">
            <div className="flex items-center gap-3">
                <h1 className="text-[15px] font-semibold text-fg-1">
                    Bandeja de incidentes
                </h1>
                <div className="flex items-center gap-2 text-[12px] text-fg-3">
                    <span>
                        <span className="font-medium text-fg-1">
                            {openCount}
                        </span>{' '}
                        abiertos
                    </span>
                    <span>·</span>
                    <span className="flex items-center gap-1">
                        <span className="relative inline-flex size-1.5">
                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-severity-critical opacity-60" />
                            <span className="relative inline-flex size-1.5 rounded-full bg-severity-critical" />
                        </span>
                        <span className="font-medium text-severity-critical">
                            {criticalCount}
                        </span>{' '}
                        críticos
                    </span>
                </div>
            </div>

            <div className="flex items-center gap-2">
                {/* Layout switcher */}
                <div className="flex items-center gap-0.5 rounded-md border border-border bg-surface-2 p-0.5">
                    {layouts.map((l) => (
                        <button
                            key={l.value}
                            type="button"
                            onClick={() => setLayout(l.value)}
                            className={cn(
                                'inline-flex items-center gap-1 rounded-sm px-2 py-1 text-[11px] font-medium transition-colors',
                                layout === l.value
                                    ? 'bg-surface-1 text-fg-1 shadow-sm'
                                    : 'text-fg-3 hover:text-fg-2',
                            )}
                            title={l.label}
                        >
                            {l.icon}
                        </button>
                    ))}
                </div>

                <Button variant="ghost" size="sm">
                    <RefreshCw size={13} />
                    Refrescar
                </Button>

                <Button variant="outline" size="sm">
                    Asignarme crítico más viejo
                </Button>
            </div>
        </header>
    );
}

// ---- TabBar ----

interface TabBarProps {
    tab: InboxTab;
    setTab: (t: InboxTab) => void;
    density: InboxDensity;
    setDensity: (d: InboxDensity) => void;
    openIncidents: IncidentDetail[];
}

const TABS: { value: InboxTab; label: string }[] = [
    { value: 'open', label: 'Abiertos' },
    { value: 'mine', label: 'Míos' },
    { value: 'unassigned', label: 'Sin asignar' },
    { value: 'sla', label: 'SLA crítico' },
    { value: 'all', label: 'Todos' },
    { value: 'discarded', label: 'Descartados' },
];

const DENSITY_OPTS: { value: InboxDensity; label: string }[] = [
    { value: 'compact', label: 'C' },
    { value: 'comfortable', label: 'M' },
    { value: 'relaxed', label: 'R' },
];

function TabBar({
    tab,
    setTab,
    density,
    setDensity,
    openIncidents,
}: TabBarProps) {
    return (
        <div className="flex shrink-0 items-center justify-between border-b border-border bg-surface-1 px-5">
            <nav className="flex items-center gap-0">
                {TABS.map((t) => (
                    <button
                        key={t.value}
                        type="button"
                        onClick={() => setTab(t.value)}
                        className={cn(
                            '-mb-px border-b-2 px-3.5 py-2.5 text-[12px] font-medium transition-colors',
                            tab === t.value
                                ? 'border-primary text-fg-1'
                                : 'border-transparent text-fg-3 hover:text-fg-2',
                        )}
                    >
                        {t.label}
                        {t.value === 'open' && openIncidents.length > 0 && (
                            <span className="ml-1.5 font-mono text-[10px] text-fg-3">
                                {openIncidents.length}
                            </span>
                        )}
                    </button>
                ))}
            </nav>

            {/* Density */}
            <div className="flex items-center gap-1 py-1.5">
                {DENSITY_OPTS.map((d) => (
                    <button
                        key={d.value}
                        type="button"
                        onClick={() => setDensity(d.value)}
                        className={cn(
                            'h-6 w-6 rounded-sm text-[10px] font-semibold transition-colors',
                            density === d.value
                                ? 'bg-surface-3 text-fg-1'
                                : 'text-fg-3 hover:text-fg-2',
                        )}
                        title={d.value}
                    >
                        {d.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

// ---- FilterBar ----

function FilterBar() {
    return (
        <div className="flex shrink-0 items-center gap-2 border-b border-border bg-background px-5 py-2">
            <div className="mr-1 flex items-center gap-1.5 rounded-md border border-border bg-surface-1 px-2.5 py-1.5 text-[12px] text-fg-3">
                <Search size={12} />
                <input
                    type="text"
                    placeholder="Buscar incidente…"
                    className="w-40 border-none bg-transparent text-[12px] text-fg-1 outline-none placeholder:text-fg-3"
                />
            </div>

            {['Severidad', 'Estado', 'Proveedor', 'Turno'].map((f) => (
                <button
                    key={f}
                    type="button"
                    className="flex items-center gap-1 rounded-sm border border-border bg-surface-1 px-2.5 py-1.5 text-[11px] text-fg-2 transition-colors hover:border-border-strong"
                >
                    <Filter size={11} />
                    {f}
                </button>
            ))}

            <button
                type="button"
                className="flex items-center gap-1 rounded-sm border border-dashed border-border px-2.5 py-1.5 text-[11px] text-fg-3 transition-colors hover:border-border-strong"
            >
                + Agregar filtro
            </button>
        </div>
    );
}

// ---- InboxFooter ----

function InboxFooter({ count, total }: { count: number; total: number }) {
    return (
        <div className="flex shrink-0 items-center justify-between border-t border-border bg-surface-1 px-5 py-2">
            <span className="text-[11px] text-fg-3">
                {count} de {total} incidentes
            </span>
            <div className="flex items-center gap-2 font-mono text-[10px] text-fg-3">
                <span className="sam-kbd">J</span>
                <span className="sam-kbd">K</span>
                <span>navegar</span>
                <span className="sam-kbd ml-2">A</span>
                <span>asignar</span>
                <span className="sam-kbd ml-2">X</span>
                <span>seleccionar</span>
                <span className="sam-kbd ml-2">Enter</span>
                <span>abrir</span>
            </div>
        </div>
    );
}

// ---- Main page ----

export default function IncidentsIndex() {
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [layout, setLayout] = useState<InboxLayout>('table');
    const [density, setDensity] = useState<InboxDensity>('comfortable');
    const [tab, setTab] = useState<InboxTab>('open');
    const [selectedSet, setSelectedSet] = useState<Set<string>>(new Set());

    const openIncidents = useMemo(
        () =>
            INCIDENTS.filter(
                (i) => !['resolved', 'closed', 'discarded'].includes(i.status),
            ),
        [],
    );

    const critical = openIncidents.filter(
        (i) => i.severity === 'critical',
    ).length;

    const selectedIncident = INCIDENTS.find((i) => i.id === selectedId) ?? null;

    const rows = useMemo(() => {
        let source: IncidentDetail[];

        switch (tab) {
            case 'open':
                source = openIncidents;
                break;
            case 'mine':
                source = INCIDENTS.filter((i) => i.assignee?.initials === 'MG');
                break;
            case 'unassigned':
                source = openIncidents.filter((i) => !i.assignee);
                break;
            case 'sla':
                source = openIncidents
                    .filter((i) => i.slaSeconds < 900)
                    .sort((a, b) => a.slaSeconds - b.slaSeconds);
                break;
            case 'discarded':
                source = INCIDENTS.filter((i) => i.status === 'discarded');
                break;
            default:
                source = INCIDENTS;
        }

        if (tab !== 'sla') {
            return [...source].sort((a, b) => a.slaSeconds - b.slaSeconds);
        }

        return source;
    }, [tab, openIncidents]);

    const handleToggle = (id: string) => {
        setSelectedSet((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const handleSelectAll = () => {
        if (selectedSet.size === rows.length) {
            setSelectedSet(new Set());
        } else {
            setSelectedSet(new Set(rows.map((r) => r.id)));
        }
    };

    const handleSelect = (id: string) => {
        setSelectedId((prev) => (prev === id ? null : id));
    };

    return (
        <>
            <Head title="Incidentes" />
            <div
                className={cn(
                    'flex min-h-0 flex-1 overflow-hidden',
                    selectedIncident
                        ? 'grid grid-cols-[1fr_minmax(520px,700px)]'
                        : '',
                )}
            >
                {/* INBOX PANEL */}
                <div className="flex min-h-0 min-w-0 flex-col overflow-hidden">
                    {selectedSet.size > 0 && (
                        <BulkBar
                            count={selectedSet.size}
                            onClear={() => setSelectedSet(new Set())}
                        />
                    )}

                    <PageHead
                        openCount={openIncidents.length}
                        criticalCount={critical}
                        layout={layout}
                        setLayout={setLayout}
                    />

                    <TabBar
                        tab={tab}
                        setTab={setTab}
                        density={density}
                        setDensity={setDensity}
                        openIncidents={openIncidents}
                    />

                    <FilterBar />

                    {layout === 'table' && (
                        <InboxTable
                            rows={rows}
                            selectedId={selectedId}
                            selectedSet={selectedSet}
                            density={density}
                            onSelect={handleSelect}
                            onToggle={handleToggle}
                            onSelectAll={handleSelectAll}
                            allChecked={
                                rows.length > 0 &&
                                selectedSet.size === rows.length
                            }
                        />
                    )}
                    {layout === 'grouped' && (
                        <InboxGrouped
                            rows={rows}
                            selectedId={selectedId}
                            selectedSet={selectedSet}
                            density={density}
                            onSelect={handleSelect}
                            onToggle={handleToggle}
                        />
                    )}
                    {layout === 'stream' && (
                        <InboxStream
                            rows={rows}
                            selectedId={selectedId}
                            onSelect={handleSelect}
                        />
                    )}

                    <InboxFooter count={rows.length} total={INCIDENTS.length} />
                </div>

                {/* DETAIL PANEL */}
                {selectedIncident && (
                    <IncidentDetailPanel
                        incident={selectedIncident}
                        onClose={() => setSelectedId(null)}
                    />
                )}
            </div>
        </>
    );
}

IncidentsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Incidentes',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/incidents`
                : '/incidents',
        },
    ],
});
