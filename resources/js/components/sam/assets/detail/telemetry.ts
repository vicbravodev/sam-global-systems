import {
    Camera,
    Flame,
    Gauge,
    Key,
    Route,
    Thermometer,
    BatteryMedium,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { formatNumber } from '@/lib/format';
import { connectivityLabel, CONNECTIVITY_LABELS } from '@/lib/labels';
import type { TelemetryEntry } from '@/types/assets';

export const MOVING_SPEED_KPH = 5;

// Engine state arrives verbatim from the provider so the stored reading stays
// faithful to the source; translating it is a presentation concern.
const ENGINE_STATE_LABELS: Record<string, string> = {
    On: 'Encendido',
    Off: 'Apagado',
    Idle: 'Ralentí',
};

export const TELEMETRY_ICONS: Record<string, LucideIcon> = {
    speed: Gauge,
    fuel: Flame,
    temperature: Thermometer,
    camera_status: Camera,
    battery: BatteryMedium,
    ignition: Key,
    odometer: Route,
};

const UNIT_LABELS: Record<string, string> = {
    percent: '%',
    celsius: '°C',
    km: 'km',
    'km/h': 'km/h',
    volts: 'V',
};

export function telemetryValue(data: TelemetryEntry['data']): {
    value: string;
    unit: string | null;
} {
    if (data === null) {
        return { value: '—', unit: null };
    }

    const raw = data.value;
    const unit =
        typeof data.unit === 'string'
            ? (UNIT_LABELS[data.unit] ?? data.unit)
            : null;

    if (typeof raw === 'string') {
        return {
            value:
                ENGINE_STATE_LABELS[raw] ??
                (raw in CONNECTIVITY_LABELS ? connectivityLabel(raw) : raw),
            unit,
        };
    }

    if (typeof raw === 'number') {
        return {
            value: formatNumber(raw, { maximumFractionDigits: 1 }),
            unit,
        };
    }

    return { value: JSON.stringify(data), unit: null };
}
