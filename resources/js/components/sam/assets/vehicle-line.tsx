import type { AssetVehicleFacts } from '@/types/assets';

/** "Kenworth T680 2022" or null when nothing is known. */
export function vehicleTitle(vehicle: AssetVehicleFacts | null): string | null {
    if (vehicle === null) {
        return null;
    }

    const parts = [vehicle.make, vehicle.model, vehicle.year]
        .filter((p) => p !== null && p !== '')
        .map(String);

    return parts.length > 0 ? parts.join(' ') : null;
}

/** Plate as a small mono chip. */
export function PlateChip({ plate }: { plate: string }) {
    return (
        <span className="inline-flex items-center rounded-sm border border-border-strong bg-surface-2 px-1.5 py-px font-mono text-3xs font-semibold tracking-label text-fg-1 uppercase">
            {plate}
        </span>
    );
}
