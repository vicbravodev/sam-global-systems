<?php

namespace Tests\Feature\Support;

use App\Support\SamMailMessage;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\TestCase;

/**
 * La plantilla de marca SAM (resources/views/vendor/notifications y
 * vendor/mail) se renderiza de verdad: tono, etiqueta, ficha de datos,
 * pie propio y nada del layout genérico de Laravel.
 */
class SamMailMessageTest extends TestCase
{
    public function test_it_renders_the_branded_layout_with_details(): void
    {
        $html = (string) (new SamMailMessage)
            ->error()
            ->eyebrow('Alerta de plataforma')
            ->greeting('Nadie atendió el incidente')
            ->line('Contacta al cliente.')
            ->details(['Tenant' => 'Transportes del Norte', 'Prioridad' => 'critical', 'Vacío' => null], 'Detalle')
            ->action('Abrir incidente', 'https://sam.test/incidentes/1')
            ->render();

        $this->assertStringContainsString('images/brand/sam-emblem.png', $html);
        $this->assertStringContainsString('Sistema Automatizado de Monitoreo', $html);
        $this->assertStringContainsString('tone-bar-danger', $html);
        $this->assertStringContainsString('Alerta de plataforma', $html);
        $this->assertStringContainsString('Transportes del Norte', $html);
        $this->assertStringNotContainsString('Vacío', $html, 'Las filas vacías no se pintan.');
        $this->assertStringContainsString('button-error', $html);
        $this->assertStringContainsString('Equipo SAM Global Systems', $html);
        $this->assertStringNotContainsString('laravel.com', $html);
        $this->assertStringNotContainsString('Regards', $html);
        // El CSS del tema se aplica en línea (los clientes de correo ignoran <style>).
        $this->assertStringContainsString('background-color: #d92d20', $html);
    }

    public function test_details_are_escaped(): void
    {
        $html = (string) (new SamMailMessage)
            ->details(['Mensaje' => '<script>alert(1)</script>'])
            ->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_tone_defaults_to_the_brand_color_and_can_be_overridden(): void
    {
        $this->assertStringContainsString('tone-bar-primary', (string) (new SamMailMessage)->line('Hola')->render());
        $this->assertStringContainsString('tone-bar-warning', (string) (new SamMailMessage)->tone(SamMailMessage::TONE_WARNING)->line('Hola')->render());
    }

    public function test_the_preheader_defaults_to_the_first_intro_line(): void
    {
        $html = (string) (new SamMailMessage)->line('Primera línea del aviso')->render();

        $this->assertMatchesRegularExpression('/class="preheader"[^>]*>Primera línea del aviso/u', $html);
    }

    public function test_framework_notifications_also_use_the_brand_template(): void
    {
        $notifiable = new class
        {
            public string $email = 'ana@example.com';

            public function getEmailForPasswordReset(): string
            {
                return $this->email;
            }
        };

        $html = (string) (new ResetPassword('token'))->toMail($notifiable)->render();

        $this->assertStringContainsString('images/brand/sam-emblem.png', $html);
        $this->assertStringContainsString('Equipo SAM Global Systems', $html);
    }
}
