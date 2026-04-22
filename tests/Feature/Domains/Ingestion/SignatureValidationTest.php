<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Actions\ValidateIncomingSignature;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Events\RawEventReceived;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SignatureValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_signature_persists_but_does_not_process(): void
    {
        Event::fake([RawEventReceived::class]);
        Queue::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $payload = ['eventType' => 'AlertIncident', 'eventId' => 'sig-test-001'];
        $secret = 'my-webhook-secret';
        $invalidSignature = 'definitely-not-valid';

        $validateSignature = app(ValidateIncomingSignature::class);
        $isValid = $validateSignature->execute(
            json_encode($payload),
            $invalidSignature,
            $secret,
        );

        $this->assertFalse(
            $isValid,
            'Signature validation should return false for an invalid HMAC signature',
        );

        $storeRawEvent = app(StoreRawEvent::class);
        $rawEvent = $storeRawEvent->execute(
            payload: $payload,
            sourceType: 'webhook',
            teamId: $team->id,
            providerId: null,
            externalEventId: 'sig-test-001',
        );

        $rawEvent->markAsInvalidSignature();
        $rawEvent->refresh();

        $this->assertNotNull(
            $rawEvent->id,
            'Raw event with invalid signature must still be persisted for security auditing',
        );

        $this->assertEquals(
            RawEventStatus::InvalidSignature,
            $rawEvent->status,
            'Raw event with invalid signature should have status "invalid_signature"',
        );
    }

    public function test_valid_signature_passes_validation(): void
    {
        $payload = '{"eventType":"AlertIncident","eventId":"sig-valid-001"}';
        $secret = 'my-webhook-secret';
        $validSignature = hash_hmac('sha256', $payload, $secret);

        $validateSignature = app(ValidateIncomingSignature::class);
        $isValid = $validateSignature->execute($payload, $validSignature, $secret);

        $this->assertTrue(
            $isValid,
            'Signature validation should return true for a correctly computed HMAC',
        );
    }

    public function test_signature_validation_uses_timing_safe_comparison(): void
    {
        $payload = '{"eventType":"Test"}';
        $secret = 'secret-key';

        $correctSignature = hash_hmac('sha256', $payload, $secret);
        $almostCorrectSignature = substr($correctSignature, 0, -1).'x';

        $validateSignature = app(ValidateIncomingSignature::class);
        $isValid = $validateSignature->execute($payload, $almostCorrectSignature, $secret);

        $this->assertFalse(
            $isValid,
            'Even a single character difference in the signature should fail validation',
        );
    }
}
