<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * La suite nunca habla con proveedores reales aunque el `.env` local tenga
 * credenciales de verdad: el 2026-10-03 una corrida con el `.env` de
 * desarrollo mandó SMS reales ("Preview body") a números de las factories.
 * phpunit.xml deja vacías estas variables; este test lo fija.
 */
class HermeticExternalCredentialsTest extends TestCase
{
    public function test_the_suite_never_sees_real_twilio_credentials(): void
    {
        foreach ([
            'services.twilio.account_sid',
            'services.twilio.auth_token',
            'services.twilio.sms_from',
            'services.twilio.whatsapp_from',
            'services.twilio.voice_from',
            'services.twilio.public_base_url',
            'services.twilio.status_callback_url',
        ] as $key) {
            $this->assertEmpty(config($key), "{$key} debe estar vacío en tests (phpunit.xml)");
        }
    }
}
