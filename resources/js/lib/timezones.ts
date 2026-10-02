/**
 * Zonas horarias ofrecidas al dar de alta un cliente. Rigen horario
 * silencioso, contexto de IA y Copiloto del tenant (`teams.timezone`).
 */
export const TENANT_TIMEZONES: { value: string; label: string }[] = [
    { value: 'America/Mexico_City', label: 'Centro de México (CDMX, GDL)' },
    { value: 'America/Monterrey', label: 'Noreste (Monterrey)' },
    { value: 'America/Chihuahua', label: 'Chihuahua' },
    { value: 'America/Mazatlan', label: 'Pacífico (Mazatlán, Culiacán)' },
    { value: 'America/Hermosillo', label: 'Sonora (sin horario de verano)' },
    { value: 'America/Tijuana', label: 'Noroeste (Tijuana)' },
    { value: 'America/Cancun', label: 'Sureste (Cancún)' },
    { value: 'America/Chicago', label: 'EE. UU. Centro' },
    { value: 'America/Los_Angeles', label: 'EE. UU. Pacífico' },
    { value: 'America/Bogota', label: 'Colombia' },
];

export const DEFAULT_TENANT_TIMEZONE = 'America/Mexico_City';

export function timezoneLabel(value: string | null | undefined): string {
    if (!value) {
        return 'Sin definir (UTC)';
    }

    return TENANT_TIMEZONES.find((tz) => tz.value === value)?.label ?? value;
}
