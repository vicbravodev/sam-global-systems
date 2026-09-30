<?php

namespace App\Domains\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
        // `Content` no tiene `textString`: el texto plano sale de una vista
        // que imprime el cuerpo tal cual (sin escapar, no es HTML).
        return new Content(
            text: 'mail.generic-notification-text',
            with: ['bodyText' => $this->bodyText],
            htmlString: nl2br(e($this->bodyText)),
        );
    }
}
