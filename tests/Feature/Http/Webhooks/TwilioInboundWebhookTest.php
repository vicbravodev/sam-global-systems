<?php

namespace Tests\Feature\Http\Webhooks;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Roadmap B9: inbound Twilio replies acknowledge / dismiss / escalate the
 * incident correlated by the reply token.
 */
class TwilioInboundWebhookTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private const AUTH_TOKEN = 'tok-456';

    private const TWILIO_NUMBER = '+14155238886';

    private const OPERATOR_PHONE = '+5215512345678';

    private Team $team;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentStatusSeeder::class);

        $this->operator = User::factory()->create();
        $this->team = $this->operator->currentTeam;

        // SAM's platform Twilio account signs every webhook (TWILIO_*).
        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.auth_token', self::AUTH_TOKEN);

        NotificationChannel::factory()->sms()->create([
            'config_json' => ['from' => self::TWILIO_NUMBER],
        ]);
    }

    private function makeToken(array $overrides = []): NotificationReplyToken
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        return NotificationReplyToken::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'incident_id' => $incident->id,
            'user_id' => $this->operator->id,
            'channel_type' => ChannelType::Sms,
            'address' => self::OPERATOR_PHONE,
            'token' => 'W4K9',
        ], $overrides));
    }

    private function postReply(string $body, array $overrides = [], ?string $authToken = self::AUTH_TOKEN): TestResponse
    {
        $params = array_merge([
            'From' => self::OPERATOR_PHONE,
            'To' => self::TWILIO_NUMBER,
            'Body' => $body,
        ], $overrides);

        $url = url('/api/webhooks/twilio');

        $signature = $authToken !== null
            ? (new RequestValidator($authToken))->computeSignature($url, $params)
            : 'forged-signature';

        return $this->post('/api/webhooks/twilio', $params, ['X-Twilio-Signature' => $signature]);
    }

    /**
     * TrimStrings recortaba el cuerpo antes de validar la firma: una
     * respuesta con salto de línea final (lo normal al teclear en el
     * teléfono) daba 403 y el incidente nunca se reconocía.
     */
    public function test_a_reply_with_trailing_whitespace_still_validates_and_acknowledges(): void
    {
        $token = $this->makeToken();
        $params = ['From' => self::OPERATOR_PHONE, 'To' => self::TWILIO_NUMBER, 'Body' => "SI-W4K9 \n"];
        $signature = (new RequestValidator(self::AUTH_TOKEN))->computeSignature(url('/api/webhooks/twilio'), $params);

        $this->call('POST', '/api/webhooks/twilio', $params, [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_TWILIO_SIGNATURE' => $signature,
        ], http_build_query($params))->assertOk();

        $this->assertNotNull(Incident::withoutGlobalScopes()->find($token->incident_id)->acknowledged_at);
    }

    public function test_invalid_signature_is_rejected_with_403(): void
    {
        $this->makeToken();

        $this->postReply('SI-W4K9', authToken: null)->assertForbidden();

        $ctx = $this->assertSystemLogged('webhook.twilio.signature_rejected', fn (array $c) => $c['reason'] === 'hmac_mismatch');
        $this->assertSame('inbound', $ctx['input']['endpoint']);
        $this->assertSame(3, $ctx['calc']['signed_params_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_unknown_twilio_number_is_rejected_with_403(): void
    {
        $this->makeToken();

        $this->postReply('SI-W4K9', ['To' => '+10000000000'])->assertForbidden();

        $ctx = $this->assertSystemLogged('webhook.twilio.unknown_number', fn (array $c) => $c['reason'] === 'not_platform_sender');
        $this->assertSame(['to_present' => true, 'to_channel' => 'sms'], $ctx['calc']);
        $this->assertStringNotContainsString('10000000000', (string) json_encode($this->systemLogEntries()));
        $this->assertSystemNotLogged('webhook.twilio.signature_rejected');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_si_reply_acknowledges_the_incident(): void
    {
        $token = $this->makeToken();

        $response = $this->postReply('SI-W4K9');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContainsString('confirmado', $response->getContent());

        $incident = $token->incident()->first();
        // The reply names the per-tenant reference, never the global id.
        $this->assertStringContainsString($incident->reference(), $response->getContent());
        $this->assertNotNull($incident->acknowledged_at);
        $this->assertSame($this->operator->id, $incident->acknowledged_by);

        $token->refresh();
        $this->assertNotNull($token->consumed_at);
        $this->assertSame('SI', $token->consumed_action);

        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('title', 'Incident acknowledged via sms')
            ->count());

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('action', 'incident.reply.si')
            ->count());

        $this->assertReplyApplied($token, 'SI', 'acknowledge');
        $this->assertNoReplySecretsLogged($token, 'SI-W4K9');
    }

    public function test_no_reply_dismisses_the_incident_as_false_positive(): void
    {
        $token = $this->makeToken();

        $response = $this->postReply('NO-W4K9');

        $response->assertOk();
        $this->assertStringContainsString('descartado', $response->getContent());

        $incident = $token->incident()->first()->fresh('status');
        $this->assertSame(IncidentStatusCode::FalsePositive->value, $incident->status?->code);

        $this->assertReplyApplied($token, 'NO', 'dismiss');
        $this->assertNoReplySecretsLogged($token, 'NO-W4K9');
    }

    public function test_esc_reply_escalates_the_incident(): void
    {
        $token = $this->makeToken();

        $response = $this->postReply('ESC-W4K9');

        $response->assertOk();
        $this->assertStringContainsString('escalado', $response->getContent());

        $incident = $token->incident()->first()->fresh('status');
        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->status?->code);

        $this->assertReplyApplied($token, 'ESC', 'escalate');
        $this->assertNoReplySecretsLogged($token, 'ESC-W4K9');
    }

    public function test_expired_token_takes_no_action(): void
    {
        $token = $this->makeToken(['expires_at' => now()->subHour()]);

        $response = $this->postReply('SI-W4K9');

        $response->assertOk();
        $this->assertStringContainsString('expirado', $response->getContent());
        $this->assertNull($token->incident()->first()->acknowledged_at);

        $this->assertSystemLogged('notifications.inbound_reply.ignored', fn (array $c) => $c['reason'] === 'token_expired'
            && $c['input'] === $this->replyInput($token));
        $this->assertSystemNotLogged('notifications.inbound_reply.applied');
        $this->assertNoReplySecretsLogged($token, 'SI-W4K9');
    }

    public function test_reply_to_a_closed_incident_is_ignored_as_terminal(): void
    {
        $closed = Incident::factory()->closed()->create(['team_id' => $this->team->id]);
        $token = $this->makeToken(['incident_id' => $closed->id]);

        $response = $this->postReply('SI-W4K9');

        $response->assertOk();
        $this->assertStringContainsString('ya está cerrado', $response->getContent());
        $this->assertSame('noop_terminal', $token->fresh()->consumed_action);

        $this->assertSystemLogged('notifications.inbound_reply.ignored', fn (array $c) => $c['reason'] === 'incident_terminal'
            && $c['input'] === $this->replyInput($token)
            && $c['result'] === ['consumed_action' => 'noop_terminal']);
        $this->assertNoReplySecretsLogged($token, 'SI-W4K9');
    }

    public function test_second_reply_is_idempotent(): void
    {
        $token = $this->makeToken();

        $this->postReply('SI-W4K9')->assertOk();
        $firstAck = $token->incident()->first()->acknowledged_at;

        $response = $this->postReply('NO-W4K9');

        $response->assertOk();
        $this->assertStringContainsString('Ya registramos', $response->getContent());

        $incident = $token->incident()->first()->fresh('status');
        $this->assertEquals($firstAck, $incident->acknowledged_at);
        $this->assertNotSame(IncidentStatusCode::FalsePositive->value, $incident->status?->code);

        $this->assertCount(1, $this->systemLogEntries('notifications.inbound_reply.applied'));
        $this->assertSystemLogged('notifications.inbound_reply.ignored', fn (array $c) => $c['reason'] === 'already_consumed'
            && $c['input'] === $this->replyInput($token)
            && $c['result'] === ['consumed_action' => 'SI']);
        $this->assertNoReplySecretsLogged($token, 'NO-W4K9');
    }

    /**
     * @return array<string, mixed>
     */
    private function replyInput(NotificationReplyToken $token): array
    {
        return ['token_id' => $token->id, 'incident_id' => $token->incident_id, 'channel_type' => 'sms'];
    }

    private function assertReplyApplied(NotificationReplyToken $token, string $keyword, string $action): void
    {
        $this->assertSystemLogged('notifications.inbound_reply.applied', fn (array $c) => $c['input'] === $this->replyInput($token)
            && $c['calc'] === ['keyword' => $keyword, 'user_linked' => true]
            && $c['result'] === ['action' => $action]);
    }

    private function assertNoReplySecretsLogged(NotificationReplyToken $token, string $body): void
    {
        $json = json_encode($this->systemLogEntries());

        $this->assertStringNotContainsString($token->token, $json);
        $this->assertStringNotContainsString($body, $json);
        $this->assertStringNotContainsString(ltrim(self::OPERATOR_PHONE, '+'), $json);
        $this->assertStringNotContainsString(ltrim((string) $token->address, '+'), $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_unknown_token_is_answered_with_silence(): void
    {
        $this->makeToken();

        $response = $this->postReply('SI-ZZZZ');

        $response->assertOk();
        $this->assertStringNotContainsString('<Message>', $response->getContent());
    }

    public function test_reply_from_unexpected_sender_is_ignored(): void
    {
        $token = $this->makeToken();

        $response = $this->postReply('SI-W4K9', ['From' => '+5219998887766']);

        $response->assertOk();
        $this->assertStringNotContainsString('<Message>', $response->getContent());
        $this->assertNull($token->incident()->first()->acknowledged_at);
    }

    public function test_reply_token_acts_only_on_its_own_tenant(): void
    {
        // Every number is SAM's: the token itself names the tenant. A reply
        // must act on the token's incident and never touch another tenant.
        $mine = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $otherTeam = User::factory()->create()->currentTeam;
        $otherIncident = Incident::factory()->open()->create(['team_id' => $otherTeam->id]);

        NotificationReplyToken::factory()->create([
            'team_id' => $otherTeam->id,
            'incident_id' => $otherIncident->id,
            'channel_type' => ChannelType::Sms,
            'address' => self::OPERATOR_PHONE,
            'token' => 'X7P2',
        ]);

        $response = $this->postReply('SI-X7P2');

        $response->assertOk();
        $this->assertNotNull($otherIncident->fresh()->acknowledged_at);
        $this->assertNull($mine->fresh()->acknowledged_at);
    }

    public function test_no_platform_account_rejects_every_reply(): void
    {
        config()->set('services.twilio.auth_token', null);
        $this->makeToken();

        $this->postReply('SI-W4K9')->assertForbidden();
    }

    public function test_message_without_keyword_is_answered_with_silence(): void
    {
        $this->makeToken();

        $response = $this->postReply('gracias');

        $response->assertOk();
        $this->assertStringNotContainsString('<Message>', $response->getContent());
    }
}
