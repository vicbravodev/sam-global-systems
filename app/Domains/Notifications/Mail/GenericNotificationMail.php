<?php

namespace App\Domains\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        // El HTML usa la plantilla de marca (Markdown). El cuerpo es texto del
        // tenant: se escapa y se pinta como un único bloque HTML de una línea
        // para que Markdown no interprete `#`, `*` ni saltos dobles. El texto
        // plano sale de una vista que imprime el cuerpo tal cual.
        return new Content(
            markdown: 'mail.generic-notification',
            text: 'mail.generic-notification-text',
            with: [
                'subjectLine' => $this->subjectLine,
                'bodyText' => $this->bodyText,
                'bodyHtml' => '<p class="message-body">'.str_replace(["\r\n", "\r", "\n"], '<br />', e($this->bodyText)).'</p>',
                'bodyPreview' => Str::limit((string) preg_replace('/\s+/', ' ', $this->bodyText), 140),
            ],
        );
    }
}
