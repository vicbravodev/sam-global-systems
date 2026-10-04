<?php

namespace App\Domains\Assets\Notifications;

use App\Models\Team;
use App\Support\SamMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Aviso a los responsables del tenant cuando el sync descubre unidades
 * nuevas: SAM no las vigila solo (decisión 2026-09-28), el cliente decide
 * cuáles enciende sabiendo cuánto cupo le queda.
 */
class AssetsPendingMonitoringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Team $team,
        public readonly int $newlyPending,
        public readonly int $totalPending,
        public readonly int $monitored,
        public readonly ?int $cap,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SamMailMessage
    {
        $units = $this->newlyPending === 1 ? 'unidad nueva' : 'unidades nuevas';
        $capLine = $this->cap === null
            ? "Tienes {$this->monitored} unidades vigiladas y sin tope contratado."
            : "Tienes {$this->monitored} de {$this->cap} unidades vigiladas."
                .($this->monitored >= $this->cap
                    ? ' Encender más se cobra como extra por cada día que estén encendidas.'
                    : ' Aún tienes cupo dentro de lo contratado.');

        return (new SamMailMessage)
            ->subject("{$this->newlyPending} {$units} sin vigilar en {$this->team->name}")
            ->tone(SamMailMessage::TONE_WARNING)
            ->eyebrow('Flota · '.$this->team->name)
            ->greeting($this->newlyPending === 1 ? 'Encontramos una unidad nueva' : "Encontramos {$this->newlyPending} unidades nuevas")
            ->line("La sincronización encontró {$this->newlyPending} {$units} en tu flota. SAM no las vigila hasta que tú lo decidas.")
            ->line($capLine)
            ->details([
                'Unidades nuevas' => $this->newlyPending,
                'Pendientes en total' => $this->totalPending,
                'Vigiladas' => $this->cap === null ? (string) $this->monitored : "{$this->monitored} de {$this->cap}",
            ], 'Resumen de tu flota')
            ->action('Elegir qué unidades vigilar', route('assets.index', ['current_team' => $this->team->slug, 'monitoring' => 'pending']));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'team_id' => $this->team->id,
            'newly_pending' => $this->newlyPending,
            'total_pending' => $this->totalPending,
            'monitored' => $this->monitored,
            'cap' => $this->cap,
        ];
    }
}
