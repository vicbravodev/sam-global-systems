<?php

namespace App\Domains\Automation\Enums;

enum ActionExecutionStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Retrying = 'retrying';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Por confirmar',
            self::Queued => 'En cola',
            self::Running => 'En curso',
            self::Completed => 'Completada',
            self::Failed => 'Fallida',
            self::Cancelled => 'Cancelada',
            self::Retrying => 'Reintentando',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
