<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Mail\GenericNotificationMail;
use Tests\TestCase;

/**
 * Los tests de envío usan `Mail::fake()`, que nunca construye el contenido:
 * aquí se renderiza el correo de verdad (HTML y texto plano) para que un
 * `Content` inválido no pase desapercibido.
 */
class GenericNotificationMailTest extends TestCase
{
    public function test_it_renders_html_and_plain_text_bodies(): void
    {
        $mail = new GenericNotificationMail(
            'Pánico en ROBUST VW',
            "Unidad ROBUST VW activó el botón de pánico.\nUbicación: <Autopista 57>",
        );

        $mail->assertHasSubject('Pánico en ROBUST VW');

        $mail->assertSeeInHtml('Unidad ROBUST VW activó el botón de pánico.<br />', false);
        $mail->assertSeeInHtml('&lt;Autopista 57&gt;', false);
        $mail->assertDontSeeInHtml('<Autopista 57>', false);

        $mail->assertSeeInText("Unidad ROBUST VW activó el botón de pánico.\nUbicación: <Autopista 57>", false);
    }
}
