<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Actions\AnswerCopilotQuestion;
use App\Domains\Copilot\Data\CopilotAnswer;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Incidents\Models\Incident;
use App\Models\Team;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Fuga cross-tenant sobre el camino real del Copilot (CLAUDE.md §2.1 punto 8).
 * Los números económicos ("T555") NO son únicos entre tenants: dos flotas
 * pueden tener su T555. El Copilot de B jamás puede responder con la de A.
 */
class CopilotTenantLeakTest extends TestCase
{
    use AssertsTenantIsolation, CopilotFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
    }

    public function test_answering_in_tenant_b_never_reads_tenant_a_units_or_incidents(): void
    {
        $victim = Team::factory()->create();
        $attacker = Team::factory()->create();

        $victimTruck = $this->truckWithTelemetry($victim, 'T555');
        Incident::factory()->count(2)->create(['team_id' => $victim->id, 'asset_id' => $victimTruck->id]);

        $answer = fn (string $question, array $hints = []) => $this->assertNoTenantLeak(
            $attacker,
            fn (): CopilotAnswer => app(AnswerCopilotQuestion::class)->execute(
                teamId: $attacker->id,
                teamSlug: $attacker->slug,
                permissions: ['copilot.use', 'assets.view', 'incidents.view', 'context.view', 'drivers.view'],
                isSuperAdmin: false,
                question: $question,
                hints: $hints,
            ),
        );

        // By code in the text: B has no T555, so it must ask which unit.
        $byText = $answer('Dame el reporte completo de la unidad T555');
        $this->assertNull($byText->resolvedContext['asset_id']);
        $this->assertSame('asset_picker', $byText->blocks()[0]['type']);
        $this->assertStringContainsString('No encontré la unidad T555', $byText->blocks()[0]['text']);
        $this->assertSame([], $byText->blocks()[0]['options']);

        // By forged id from the UI: ignored the same way.
        $byId = $answer('reporte', ['asset_id' => $victimTruck->id, 'intent' => CopilotIntent::AssetReport->value]);
        $this->assertNull($byId->resolvedContext['asset_id']);

        // Fleet-wide questions only count B's data.
        $fleet = $answer('¿Cómo está la flota?');
        $this->assertSame(0, $fleet->facts()['fleet_overview']['total']);

        $incidents = $answer('incidentes abiertos');
        $this->assertSame(0, $incidents->facts()['open_incidents']['open']);
    }

    public function test_same_unit_code_in_two_tenants_resolves_to_each_own_unit(): void
    {
        [$userA, $teamA] = $this->memberWithRole('supervisor');
        [$userB, $teamB] = $this->memberWithRole('supervisor');

        $truckA = $this->truckWithTelemetry($teamA, 'T555');
        $truckB = $this->truckWithTelemetry($teamB, 'T555');

        $this->actingAs($userB)
            ->postJson("/{$teamB->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertCreated()
            ->assertJsonPath('answer.context.resolved.asset_id', $truckB->id);

        $this->actingAs($userA)
            ->postJson("/{$teamA->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertCreated()
            ->assertJsonPath('answer.context.resolved.asset_id', $truckA->id);
    }

    public function test_other_tenant_conversations_and_messages_are_unreachable(): void
    {
        [$userA, $teamA] = $this->memberWithRole('supervisor');
        [$userB, $teamB] = $this->memberWithRole('supervisor');

        $response = $this->actingAs($userA)
            ->postJson("/{$teamA->slug}/copilot/messages", ['content' => 'estado de la flota'])
            ->assertCreated();

        $conversationId = $response->json('conversation.id');
        $answerId = $response->json('answer.id');

        // 404 (scoped binding) or 403 (policy team check): never the data.
        foreach ([
            $this->actingAs($userB)->getJson("/{$teamB->slug}/copilot/conversations/{$conversationId}"),
            $this->actingAs($userB)->postJson("/{$teamB->slug}/copilot/messages", ['content' => 'hola', 'conversation_id' => $conversationId]),
            $this->actingAs($userB)->putJson("/{$teamB->slug}/copilot/messages/{$answerId}/feedback", ['rating' => 1]),
            $this->actingAs($userB)->deleteJson("/{$teamB->slug}/copilot/conversations/{$conversationId}"),
        ] as $response) {
            $this->assertContains($response->status(), [403, 404]);
            $this->assertStringNotContainsString('estado de la flota', (string) $response->getContent());
        }

        $this->assertDatabaseHas('copilot_conversations', ['id' => $conversationId]);

        $this->assertNull(CopilotMessage::query()->withoutGlobalScopes()->findOrFail($answerId)->feedback);
    }
}
