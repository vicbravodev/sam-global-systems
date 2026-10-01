<?php

namespace Tests\Feature\Support;

use App\Domains\Notifications\Actions\ProcessInboundReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ProcessInboundReplyLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    public function test_an_unrecognizable_reply_is_logged_without_the_sender_phone(): void
    {
        $this->assertNull(app(ProcessInboundReply::class)->execute('+525512345678', 'hola que tal'));

        $this->assertSystemLogged('notifications.inbound_reply.ignored', fn (array $c) => $c['reason'] === 'no_keyword');
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('5512345678', (string) json_encode($this->systemLogEntries()));
    }

    public function test_an_unknown_token_is_logged_without_the_token_or_the_phone(): void
    {
        $this->assertNull(app(ProcessInboundReply::class)->execute('+525512345678', 'SI ZZ99'));

        $this->assertSystemLogged('notifications.inbound_reply.ignored', fn (array $c) => $c['reason'] === 'unknown_token');
        $json = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('ZZ99', $json);
        $this->assertStringNotContainsString('5512345678', $json);
    }

    public function test_a_unicode_folded_keyword_is_handled_instead_of_crashing_the_webhook(): void
    {
        // `ſ` (U+017F) pasa el patrón /iu como `S`; antes reventaba con
        // LogicException (500 en el webhook) en lugar de seguir el flujo.
        $this->assertNull(app(ProcessInboundReply::class)->execute('+525512345678', "\u{017F}I ZZ99"));

        $this->assertSystemLogged('notifications.inbound_reply.ignored', fn (array $c) => $c['reason'] === 'unknown_token');
        $this->assertNoSensitiveDataLogged();
    }
}
