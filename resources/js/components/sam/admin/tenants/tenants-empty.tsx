import { Building2, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';

export interface TenantsEmptyProps {
    /** No match for the active filters (vs. no tenant at all). */
    filtered: boolean;
    onClearFilters: () => void;
    onCreate: () => void;
}

export function TenantsEmpty({
    filtered,
    onClearFilters,
    onCreate,
}: TenantsEmptyProps) {
    return filtered ? (
        <EmptyState
            icon={Building2}
            title="Sin resultados"
            description="Ningún cliente coincide con la búsqueda o el filtro."
            action={
                <Button variant="outline" size="sm" onClick={onClearFilters}>
                    Limpiar filtros
                </Button>
            }
        />
    ) : (
        <EmptyState
            icon={Building2}
            title="Da de alta tu primer cliente"
            description="Crea la empresa y a su responsable; recibirá un correo para entrar y conectar su flota."
            action={
                <Button size="sm" onClick={onCreate}>
                    <Plus className="size-3.5" />
                    Nuevo cliente
                </Button>
            }
        />
    );
}
