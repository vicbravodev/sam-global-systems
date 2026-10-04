<?php

namespace App\Notifications;

use App\Domains\Tenancy\Models\DemoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
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

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->demoRequest;

        $mail = (new MailMessage)
            ->subject("[SAM] Nueva solicitud de demo: {$request->company}")
            ->replyTo($request->email, $request->name)
            ->greeting('Nueva solicitud de demo')
            ->line('Un prospecto pidió una demo desde el sitio. Contáctalo y marca el seguimiento en la consola.')
            ->line("Nombre: {$request->name}")
            ->line("Empresa: {$request->company}")
            ->line("Correo: {$request->email}")
            ->line('Teléfono: '.($request->phone ?? 'no lo dejó'))
            ->line("Tamaño de flota: {$request->fleet_size} unidades");

        if ($request->message !== null && $request->message !== '') {
            $mail->line('Mensaje: '.$request->message);
        }

        return $mail
            ->action('Ver en la consola', route('admin.demo-requests.index'))
            ->line('Responde a este correo para escribirle directamente al prospecto.');
    }
}
