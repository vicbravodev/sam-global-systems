<?php

namespace App\Support;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * MailMessage con las piezas de la plantilla de marca SAM
 * (resources/views/vendor/notifications/email.blade.php): etiqueta superior,
 * tono de la tarjeta, ficha de datos clave–valor y texto de vista previa.
 *
 * Los datos van en `viewData`, así que `introLines` queda para la narrativa y
 * la ficha se pinta como tabla (HTML) o como "Etiqueta: valor" (texto plano).
 */
class SamMailMessage extends MailMessage
{
    public const string TONE_PRIMARY = 'primary';

    public const string TONE_DANGER = 'danger';

    public const string TONE_WARNING = 'warning';

    public const string TONE_SUCCESS = 'success';

    /**
     * Etiqueta corta en mayúsculas sobre el título ("Alerta de plataforma").
     */
    public function eyebrow(string $text): static
    {
        $this->viewData['eyebrow'] = $text;

        return $this;
    }

    /**
     * Color de la franja superior, la etiqueta y el botón. Sin llamarlo se
     * deriva del nivel (`error()` → danger, `success()` → success).
     */
    public function tone(string $tone): static
    {
        $this->viewData['tone'] = $tone;

        return $this;
    }

    /**
     * Ficha de datos clave–valor. Los valores nulos o vacíos se omiten.
     *
     * @param  array<string, string|int|float|null>  $rows
     */
    public function details(array $rows, ?string $title = null): static
    {
        $this->viewData['details'] = array_map(
            static fn (string|int|float $value): string => (string) $value,
            array_filter($rows, static fn (string|int|float|null $value): bool => $value !== null && $value !== ''),
        );
        $this->viewData['detailsTitle'] = $title;

        return $this;
    }

    /**
     * Texto que el cliente de correo muestra junto al asunto en la bandeja.
     * Sin llamarlo se usa la primera línea de introducción.
     */
    public function preheader(string $text): static
    {
        $this->viewData['preheader'] = $text;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function detailRows(): array
    {
        /** @var array<string, string> */
        return $this->viewData['details'] ?? [];
    }
}
