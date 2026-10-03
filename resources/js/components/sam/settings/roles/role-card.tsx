import { Lock, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { RoleRow } from '@/types/sam';

interface RoleCardProps {
    role: RoleRow;
    memberCount: number;
    canManage: boolean;
    onEdit: () => void;
    onDelete: () => void;
}

export function RoleCard({
    role,
    memberCount,
    canManage,
    onEdit,
    onDelete,
}: RoleCardProps) {
    return (
        <div className="flex flex-col gap-2 rounded-lg border border-border bg-surface-1 p-4">
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1" title={role.code}>
                    <div className="flex items-center gap-1.5">
                        {role.isSystem ? (
                            <Lock
                                size={12}
                                className="shrink-0 text-fg-3"
                                aria-label="Rol predefinido"
                            />
                        ) : null}
                        <span className="truncate text-sm font-semibold text-fg-1">
                            {role.name}
                        </span>
                    </div>
                    <span className="text-2xs text-fg-3">
                        {role.isSystem
                            ? 'Predefinido por SAM'
                            : 'Creado por tu equipo'}
                    </span>
                </div>
                {canManage && role.editable ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7 shrink-0"
                                aria-label={`Acciones del rol ${role.name}`}
                            >
                                <MoreHorizontal size={14} />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={onEdit}>
                                <Pencil size={13} /> Editar
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onDelete}
                            >
                                <Trash2 size={13} /> Eliminar
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>

            {role.description ? (
                <p className="text-xs text-fg-2">{role.description}</p>
            ) : null}

            <div className="mt-auto flex flex-wrap gap-x-3 gap-y-1 pt-1 text-2xs text-fg-3">
                <span>
                    <span className="font-medium text-fg-2 tabular-nums">
                        {role.permissions.length}
                    </span>{' '}
                    {role.permissions.length === 1 ? 'permiso' : 'permisos'}
                </span>
                <span>
                    <span className="font-medium text-fg-2 tabular-nums">
                        {memberCount}
                    </span>{' '}
                    {memberCount === 1 ? 'persona' : 'personas'}
                </span>
            </div>
        </div>
    );
}
