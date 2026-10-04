<?php

namespace App\Notifications;

use App\Domains\Tenancy\Models\DemoRequest;
use App\Support\SamMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso a los super-admins de que un prospecto pidió una demo desde el sitio
 * público. Lleva los datos de contacto completos (es un correo comercial, no
 * un log) y responde directo al prospecto con Reply-To. Se envía con
 * `sendNow` desde SubmitDemoRequest: no depende de que la cola esté viva.
 */
class DemoRequestReceived extends Notification
{
    use Queueable;

    public function __construct(public readonly DemoRequest $demoRequest)
    {
        // Producto sólo en español: el pie estándar del correo no depende de
        // APP_LOCALE.
        $this->locale('es');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SamMailMessage
    {
        $request = $this->demoRequest;

        return (new SamMailMessage)
            ->subject("[SAM] Nueva solicitud de demo: {$request->company}")
            ->replyTo($request->email, $request->name)
            ->tone(SamMailMessage::TONE_SUCCESS)
            ->eyebrow('Nuevo prospecto')
            ->greeting("{$request->company} quiere conocer SAM")
            ->line("{$request->name} pidió una demo desde el sitio para una flota de {$request->fleet_size} unidades. Contáctalo y marca el seguimiento en la consola.")
            ->details([
                'Nombre' => $request->name,
                'Empresa' => $request->company,
                'Correo' => $request->email,
                'Teléfono' => $request->phone ?? 'No lo dejó',
                'Tamaño de flota' => "{$request->fleet_size} unidades",
                'Mensaje' => $request->message,
            ], 'Datos de contacto')
            ->action('Ver en la consola', route('admin.demo-requests.index'))
            ->line('Responde a este correo para escribirle directamente al prospecto.');
    }
}
