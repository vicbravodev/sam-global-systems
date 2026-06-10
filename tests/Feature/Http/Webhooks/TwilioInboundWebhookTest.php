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
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Roadmap B9: inbound Twilio replies acknowledge / dismiss / escalate the
 * incident correlated by the reply token.
 */
class TwilioInboundWebhookTest extends TestCase
{
    use RefreshDatabase;

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

        NotificationChannel::factory()->sms()->create([
            'team_id' => $this->team->id,
            'config_json' => [
                'twilio_account_sid' => 'AC123',
                'twilio_auth_token' => self::AUTH_TOKEN,
                'from' => self::TWILIO_NUMBER,
            ],
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

    public function test_invalid_signature_is_rejected_with_403(): void
    {
        $this->makeToken();

        $this->postReply('SI-W4K9', authToken: null)->assertForbidden();
    }

    public function test_unknown_twilio_number_is_rejected_with_403(): void
    {
        $this->makeToken();

        $this->postReply('SI-W4K9', ['To' => '+10000000000'])->assertForbidden();
    }

    public function test_si_reply_acknowledges_the_incident(): void
    {
        $token = $this->makeToken();

        $response = $this->postReply('SI-W4K9');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContainsString('confirmado', $response->getContent());

        $incident = $token->incident()->first();
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
    }

    public function test_no_reply_dismisses_the_incident_as_false_positive(): void
    {
        $token = $this->makeToken();

        $response = $this->postReply('NO-W4K9');

        $response->assertOk();
        $this->assertStringContainsString('descartado', $response->getContent());

        $incident = $token->incident()->first()->fresh('status');
        $this->assertSame(IncidentStatusCode::FalsePositive->value, $incident->status?->code);
    }

    public function test_esc_reply_escalates_the_incident(): void
    {
        $token = $this->makeToken();

        $response = $this->postReply('ESC-W4K9');

        $response->assertOk();
        $this->assertStringContainsString('escalado', $response->getContent());

        $incident = $token->incident()->first()->fresh('status');
        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->status?->code);
    }

    public function test_expired_token_takes_no_action(): void
    {
        $token = $this->makeToken(['expires_at' => now()->subHour()]);

        $response = $this->postReply('SI-W4K9');

        $response->assertOk();
        $this->assertStringContainsString('expirado', $response->getContent());
        $this->assertNull($token->incident()->first()->acknowledged_at);
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

    public function test_token_of_another_tenant_cannot_act_through_this_channel(): void
    {
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
        $this->assertStringNotContainsString('<Message>', $response->getContent());
        $this->assertNull($otherIncident->fresh()->acknowledged_at);
    }

    public function test_message_without_keyword_is_answered_with_silence(): void
    {
        $this->makeToken();

        $response = $this->postReply('gracias');

        $response->assertOk();
        $this->assertStringNotContainsString('<Message>', $response->getContent());
    }
}
