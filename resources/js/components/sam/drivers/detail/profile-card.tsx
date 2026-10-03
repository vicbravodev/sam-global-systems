import { IdCard } from 'lucide-react';
import {
    DescriptionItem,
    DescriptionList,
} from '@/components/sam/description-list';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import type { DriverDetail } from '@/types/drivers';

export function ProfileCard({ driver }: { driver: DriverDetail }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <IdCard size={15} /> Perfil
                </CardTitle>
            </CardHeader>
            <CardContent className="p-4">
                <DescriptionList>
                    <DescriptionItem label="Nombre">
                        {driver.firstName ?? driver.fullName}
                    </DescriptionItem>
                    <DescriptionItem label="Apellidos">
                        {driver.lastName ?? '—'}
                    </DescriptionItem>
                    <DescriptionItem label="Código de empleado" mono>
                        {driver.employeeCode ?? '—'}
                    </DescriptionItem>
                    <DescriptionItem label="ID en proveedor" mono>
                        {driver.externalPrimaryId ?? '—'}
                    </DescriptionItem>
                    {driver.providerFields.map((field) => (
                        <DescriptionItem
                            key={field.key}
                            label={field.label}
                            mono={field.key === 'license_number'}
                        >
                            {field.value}
                        </DescriptionItem>
                    ))}
                    <DescriptionItem label="Primera conexión">
                        {formatDate(driver.firstSeenAt)}
                    </DescriptionItem>
                    <DescriptionItem label="Última conexión">
                        {formatDate(driver.lastSeenAt)}
                    </DescriptionItem>
                </DescriptionList>
            </CardContent>
        </Card>
    );
}
