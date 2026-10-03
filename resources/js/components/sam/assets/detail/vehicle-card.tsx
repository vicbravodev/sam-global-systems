import { Camera, Cpu, MapPin, Truck } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PlateChip } from '@/components/sam/assets/vehicle-line';
import {
    DescriptionItem,
    DescriptionList,
} from '@/components/sam/description-list';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import { assetTypeLabel } from '@/lib/labels';
import type { AssetShowProps } from '@/types/assets';

const DEVICE_ICONS: Record<string, LucideIcon> = {
    gateway: Cpu,
    camera: Camera,
    dashcam: Camera,
    gps_tracker: MapPin,
};

export function VehicleCard({ asset }: { asset: AssetShowProps['asset'] }) {
    const vehicle = asset.vehicle;
    const rows: [string, React.ReactNode][] = [
        ['Marca', vehicle?.make ?? null],
        ['Modelo', vehicle?.model ?? null],
        ['Año', vehicle?.year ?? null],
        ['Placa', vehicle?.plate ? <PlateChip plate={vehicle.plate} /> : null],
        [
            'VIN',
            vehicle?.vin ? (
                <span className="font-mono text-xs">{vehicle.vin}</span>
            ) : null,
        ],
        [
            'Tipo',
            asset.type
                ? assetTypeLabel(asset.type.code, asset.type.name)
                : null,
        ],
        ['Proveedor', asset.provider ?? null],
        [
            'ID en proveedor',
            asset.externalPrimaryId ? (
                <span className="font-mono text-xs">
                    {asset.externalPrimaryId}
                </span>
            ) : null,
        ],
        ['Integración', asset.sourceIntegration ?? null],
        [
            'Alta en SAM',
            asset.firstSeenAt ? formatDate(asset.firstSeenAt) : null,
        ],
    ];
    const known = rows.filter(([, value]) => value !== null && value !== '');

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Truck size={15} /> Vehículo
                </CardTitle>
                {asset.devices.length > 0 && (
                    <span className="flex items-center gap-1">
                        {asset.devices.map((device) => {
                            const Icon = DEVICE_ICONS[device.deviceType] ?? Cpu;

                            return (
                                <span
                                    key={device.id}
                                    title={`${device.label}${device.externalDeviceId ? ` · ${device.externalDeviceId}` : ''}`}
                                    className="inline-flex items-center gap-1 rounded-sm border border-border bg-surface-2 px-1.5 py-0.5 font-mono text-3xs text-fg-2"
                                >
                                    <Icon size={11} strokeWidth={1.75} />
                                    {device.label}
                                </span>
                            );
                        })}
                    </span>
                )}
            </CardHeader>
            <CardContent className="p-4">
                {known.length === 0 ? (
                    <p className="text-sm text-fg-3">
                        Sin datos del vehículo todavía. Marca, modelo, placa y
                        VIN llegan con la sincronización del proveedor.
                    </p>
                ) : (
                    <DescriptionList>
                        {known.map(([label, value]) => (
                            <DescriptionItem key={label} label={label}>
                                {value}
                            </DescriptionItem>
                        ))}
                    </DescriptionList>
                )}
            </CardContent>
        </Card>
    );
}
