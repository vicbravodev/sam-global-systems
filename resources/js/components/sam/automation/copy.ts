import type { LucideIcon } from 'lucide-react';
import {
    ArrowUpCircle,
    Bell,
    Eye,
    Globe,
    Hand,
    ImageIcon,
    Mail,
    MessageCircle,
    MessageSquareText,
    Siren,
    Sparkles,
    Ticket,
    TrendingUp,
    Truck,
    UserPlus,
} from 'lucide-react';
import { delayLabel } from '@/lib/labels';
import type { Option, WorkflowRow, WorkflowStep } from './types';

/*
 * Vocabulario de la página de Automatizaciones para un operador sin
 * contexto técnico. Todo lo que el backend guarda como código
 * (`incident_created`, `send_whatsapp`, `requires_confirmation`) se traduce
 * aquí a frases; si llega un código desconocido, se cae a la etiqueta del
 * backend y nunca al código crudo.
 */

// ---- Disparadores ("Cuándo") ----

export interface TriggerCopy {
    /** Frase de la fila: "Cuando se crea un incidente". */
    sentence: string;
    /** Título corto en el selector del editor. */
    title: string;
    /** Qué significa, en una línea. */
    help: string;
    icon: LucideIcon;
    /**
     * Si el sistema lo dispara hoy. `priority_changed` y `media_arrived`
     * existen en el catálogo pero ningún proceso los emite todavía: no se
     * ofrecen al crear (sólo se muestran si una automatización ya los usa).
     */
    available: boolean;
}

export type TriggerKey =
    | 'incident_created'
    | 'incident_escalated'
    | 'decision_outcome'
    | 'manual_trigger'
    | 'priority_changed'
    | 'media_arrived';

export const TRIGGERS: Record<TriggerKey, TriggerCopy> = {
    incident_created: {
        sentence: 'Cuando se crea un incidente',
        title: 'Se crea un incidente',
        help: 'Cada vez que SAM abre un incidente nuevo. Es el caso más común.',
        icon: Siren,
        available: true,
    },
    incident_escalated: {
        sentence: 'Cuando un incidente se escala',
        title: 'Un incidente se escala',
        help: 'Cuando un incidente pasa a «Escalado» y nadie lo ha tomado todavía.',
        icon: ArrowUpCircle,
        available: true,
    },
    decision_outcome: {
        sentence: 'Cuando SAM termina de revisar un evento',
        title: 'SAM revisa un evento',
        help: 'Cada vez que SAM analiza un evento de la flota, aunque no abra incidente. Pasa muy seguido: conviene acotarlo abajo.',
        icon: Sparkles,
        available: true,
    },
    manual_trigger: {
        sentence: 'Sólo cuando alguien la ejecuta',
        title: 'Sólo cuando yo la ejecute',
        help: 'No se activa sola: la lanzas desde el menú con «Ejecutar ahora».',
        icon: Hand,
        available: true,
    },
    priority_changed: {
        sentence: 'Cuando cambia la prioridad de un incidente',
        title: 'Cambia la prioridad',
        help: 'Todavía no se activa automáticamente.',
        icon: TrendingUp,
        available: false,
    },
    media_arrived: {
        sentence: 'Cuando llegan fotos o video',
        title: 'Llegan fotos o video',
        help: 'Todavía no se activa automáticamente.',
        icon: ImageIcon,
        available: false,
    },
};

export const TRIGGER_ORDER: TriggerKey[] = [
    'incident_created',
    'incident_escalated',
    'decision_outcome',
    'manual_trigger',
    'priority_changed',
    'media_arrived',
];

/** Textos de un disparador por su código del backend (si lo conocemos). */
export function triggerCopy(
    triggerType: string | null | undefined,
): TriggerCopy | undefined {
    const byCode: Partial<Record<string, TriggerCopy>> = TRIGGERS;

    return triggerType ? byCode[triggerType] : undefined;
}

export function triggerSentence(
    triggerType: string | null,
    options: Option[],
): string {
    if (triggerType === null) {
        return 'Sin disparador';
    }

    return (
        triggerCopy(triggerType)?.sentence ??
        `Cuando: ${options.find((o) => o.value === triggerType)?.label ?? 'otro disparador'}`
    );
}

/**
 * Campos de condición que no aportan al operador: el código interno de la
 * regla (`decision_code`) y el estado nuevo de una escalación (siempre
 * «Escalado»). Se ocultan del editor salvo que la automatización ya los use.
 */
export const HIDDEN_CONDITION_FIELDS: Record<string, string[]> = {
    decision_outcome: ['decision_code'],
    incident_escalated: ['new_status'],
};

// ---- Acciones ("Qué hacer" / "A quién") ----

export type TargetKind = 'role' | 'user' | 'email' | 'phone' | 'url';

export interface ActionCopy {
    /** Selector del editor: "Enviar un WhatsApp". */
    title: string;
    /** Frase compacta de la fila: "WhatsApp" + " a Monitorista". */
    short: string;
    /** Conector con el destino ("a", "para"). */
    connector?: string;
    icon: LucideIcon;
    /** Destinos admitidos; vacío = la acción no necesita destino. */
    targets: TargetKind[];
    help: string;
    /** Aún no hace nada real (se registra como simulada). */
    comingSoon?: boolean;
}

export const ACTIONS: Record<string, ActionCopy> = {
    send_whatsapp: {
        title: 'Enviar un WhatsApp',
        short: 'WhatsApp',
        connector: 'a',
        icon: MessageCircle,
        targets: ['role', 'user', 'phone'],
        help: 'Mensaje de WhatsApp con el resumen del incidente.',
    },
    send_sms: {
        title: 'Enviar un SMS',
        short: 'SMS',
        connector: 'a',
        icon: MessageSquareText,
        targets: ['role', 'user', 'phone'],
        help: 'Mensaje de texto al celular verificado de cada persona.',
    },
    send_email: {
        title: 'Enviar un correo',
        short: 'Correo',
        connector: 'a',
        icon: Mail,
        targets: ['role', 'user', 'email'],
        help: 'Correo con el resumen y el enlace al incidente.',
    },
    send_push: {
        title: 'Avisar en la app',
        short: 'Aviso en la app',
        connector: 'a',
        icon: Bell,
        targets: ['role', 'user'],
        help: 'Notificación en el teléfono de quienes tienen la app instalada.',
    },
    assign_incident: {
        title: 'Asignar el incidente',
        short: 'Asignar',
        connector: 'a',
        icon: UserPlus,
        targets: ['user'],
        help: 'Deja el incidente a cargo de una persona del equipo.',
    },
    escalate: {
        title: 'Escalar el incidente',
        short: 'Escalar el incidente',
        icon: ArrowUpCircle,
        targets: [],
        help: 'Lo marca como escalado para que lo atienda el siguiente nivel.',
    },
    request_human_review: {
        title: 'Pedir revisión de un operador',
        short: 'Pedir revisión de un operador',
        icon: Eye,
        targets: [],
        help: 'Deja el incidente en revisión hasta que alguien lo confirme.',
    },
    call_webhook: {
        title: 'Avisar a otro sistema',
        short: 'Avisar a otro sistema',
        connector: '·',
        icon: Globe,
        targets: ['url'],
        help: 'Envía los datos del incidente a la dirección web de otro sistema (por ejemplo, el de tu cliente).',
    },
    create_ticket: {
        title: 'Crear un ticket',
        short: 'Ticket',
        connector: 'para',
        icon: Ticket,
        targets: ['role', 'user'],
        help: 'Todavía no está conectado a un sistema de tickets.',
        comingSoon: true,
    },
    update_asset_state: {
        title: 'Cambiar el estado del activo',
        short: 'Cambiar estado del activo',
        icon: Truck,
        targets: [],
        help: 'Todavía no está disponible.',
        comingSoon: true,
    },
};

export const ACTION_ORDER = [
    'send_whatsapp',
    'send_sms',
    'send_email',
    'send_push',
    'assign_incident',
    'escalate',
    'request_human_review',
    'call_webhook',
    'create_ticket',
    'update_asset_state',
];

export const TARGET_KINDS: Record<
    TargetKind,
    { label: string; placeholder: string }
> = {
    role: { label: 'Un rol', placeholder: 'Elige un rol…' },
    user: { label: 'Una persona', placeholder: 'Elige a alguien del equipo…' },
    email: { label: 'Un correo', placeholder: 'nombre@empresa.com' },
    phone: { label: 'Un teléfono', placeholder: '+52 55 1234 5678' },
    url: { label: 'Dirección web', placeholder: 'https://…' },
};

export function actionTitle(
    actionType: string | null | undefined,
    options: Option[],
): string {
    if (!actionType) {
        return 'Acción';
    }

    return (
        ACTIONS[actionType]?.title ??
        options.find((o) => o.value === actionType)?.label ??
        'Acción'
    );
}

/** "WhatsApp a Monitorista", "Escalar el incidente", "Asignar a Ana". */
export function stepPhrase(
    actionType: string | null | undefined,
    recipient: string | null | undefined,
    options: Option[],
): string {
    const copy = actionType ? ACTIONS[actionType] : undefined;
    const base =
        copy?.short ??
        options.find((o) => o.value === actionType)?.label ??
        'Acción';

    if (!recipient || !copy || copy.targets.length === 0) {
        return base;
    }

    const shown =
        copy.targets.includes('url') && recipient.startsWith('http')
            ? hostOf(recipient)
            : recipient;

    return copy.connector === '·'
        ? `${base} · ${shown}`
        : `${base} ${copy.connector ?? 'a'} ${shown}`;
}

function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

/** Nombre del destino de un paso, prefiriendo el que resuelve el backend. */
export function stepRecipient(
    workflow: WorkflowRow,
    index: number,
): string | null {
    const fromServer = workflow.stepRecipients?.[index];

    if (fromServer !== undefined) {
        return fromServer;
    }

    const label = workflow.stepTargets[index];

    if (!label || label === '—') {
        return null;
    }

    return label.replace(/^[^:]+:\s*/, '');
}

export function stepDelay(step: WorkflowStep): string | null {
    const seconds = Number(step.delay_seconds ?? 0);

    return seconds > 0
        ? `espera ${delayLabel(seconds).replace('+', '')}`
        : null;
}

export const DELAY_CHOICES: { value: string; label: string }[] = [
    { value: '0', label: 'Enseguida' },
    { value: '60', label: 'Esperar 1 min' },
    { value: '300', label: 'Esperar 5 min' },
    { value: '600', label: 'Esperar 10 min' },
    { value: '900', label: 'Esperar 15 min' },
    { value: '1800', label: 'Esperar 30 min' },
    { value: '3600', label: 'Esperar 1 h' },
];

// ---- Estados ----

/** Una automatización sólo corre si está encendida Y publicada (no borrador). */
export function isRunning(workflow: WorkflowRow): boolean {
    return workflow.isActive && workflow.status === 'active';
}

export interface StatusCopy {
    label: string;
    /** Clase de texto semántica. */
    text: string;
    /** Clase del punto. */
    dot: string;
}

const EXECUTION_STATUS: Record<string, StatusCopy> = {
    completed: {
        label: 'Hecha',
        text: 'text-severity-low',
        dot: 'bg-severity-low',
    },
    failed: {
        label: 'Falló',
        text: 'text-severity-critical',
        dot: 'bg-severity-critical',
    },
    pending: {
        label: 'Espera confirmación',
        text: 'text-severity-high',
        dot: 'bg-severity-high',
    },
    queued: { label: 'En cola', text: 'text-fg-2', dot: 'bg-fg-3' },
    running: {
        label: 'En curso',
        text: 'text-severity-info',
        dot: 'bg-severity-info',
    },
    retrying: {
        label: 'Reintentando',
        text: 'text-severity-medium',
        dot: 'bg-severity-medium',
    },
    cancelled: { label: 'Cancelada', text: 'text-fg-3', dot: 'bg-fg-3' },
};

export function executionStatus(
    status: string | null,
    fallbackLabel?: string | null,
): StatusCopy {
    return (
        (status ? EXECUTION_STATUS[status] : undefined) ?? {
            label: fallbackLabel ?? 'Sin estado',
            text: 'text-fg-3',
            dot: 'bg-fg-3',
        }
    );
}
