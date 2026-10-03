import { Mail, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { TeamInvitation } from '@/types';

export interface InvitationRowProps {
    invitation: TeamInvitation;
    canCancel: boolean;
    onCancel: (invitation: TeamInvitation) => void;
}

/** One pending invitation, cancellable when allowed. */
export function InvitationRow({
    invitation,
    canCancel,
    onCancel: confirmCancelInvitation,
}: InvitationRowProps) {
    return (
        <li
            data-test="invitation-row"
            className="flex items-center gap-3 px-5 py-3"
        >
            <div className="grid size-9 shrink-0 place-items-center rounded-full bg-surface-3">
                <Mail className="size-4 text-fg-3" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium text-fg-1">
                    {invitation.email}
                </div>
                <div className="text-xs text-fg-3">{invitation.role_label}</div>
            </div>
            {canCancel ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 text-fg-3 hover:text-severity-critical"
                            aria-label={`Cancelar la invitación de ${invitation.email}`}
                            data-test="invitation-cancel-button"
                            onClick={() => confirmCancelInvitation(invitation)}
                        >
                            <X className="size-4" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p>Cancelar invitación</p>
                    </TooltipContent>
                </Tooltip>
            ) : null}
        </li>
    );
}
