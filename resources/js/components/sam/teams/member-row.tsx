import { ChevronDown, X } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/lib/initials';
import type { RoleOption, TeamMember, TeamPermissions } from '@/types';

export interface MemberRowProps {
    member: TeamMember;
    permissions: TeamPermissions;
    availableRoles: RoleOption[];
    onChangeRole: (member: TeamMember, role: string) => void;
    onRemove: (member: TeamMember) => void;
}

/** One team member: avatar, role (editable when allowed) and remove. */
export function MemberRow({
    member,
    permissions,
    availableRoles,
    onChangeRole: updateMemberRole,
    onRemove: confirmRemoveMember,
}: MemberRowProps) {
    return (
        <li
            data-test="member-row"
            className="flex flex-wrap items-center gap-3 px-5 py-3"
        >
            <Avatar className="size-9">
                {member.avatar ? (
                    <AvatarImage src={member.avatar} alt={member.name} />
                ) : null}
                <AvatarFallback>{getInitials(member.name)}</AvatarFallback>
            </Avatar>
            <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium text-fg-1">
                    {member.name}
                </div>
                <div className="truncate text-xs text-fg-3">{member.email}</div>
            </div>
            <div className="flex items-center gap-1">
                {member.role !== 'owner' && permissions.canUpdateMember ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
                                data-test="member-role-trigger"
                            >
                                {member.role_label}
                                <ChevronDown className="size-4 opacity-50" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {availableRoles.map((role) => (
                                <DropdownMenuItem
                                    key={role.value}
                                    data-test="member-role-option"
                                    onSelect={() =>
                                        updateMemberRole(member, role.value)
                                    }
                                >
                                    {role.label}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : (
                    <span className="rounded-sm bg-surface-3 px-2 py-1 text-xs text-fg-2">
                        {member.role_label}
                    </span>
                )}
                {member.role !== 'owner' && permissions.canRemoveMember ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 text-fg-3 hover:text-severity-critical"
                                aria-label={`Quitar a ${member.name}`}
                                data-test="member-remove-button"
                                onClick={() => confirmRemoveMember(member)}
                            >
                                <X className="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            <p>Quitar del equipo</p>
                        </TooltipContent>
                    </Tooltip>
                ) : null}
            </div>
        </li>
    );
}
