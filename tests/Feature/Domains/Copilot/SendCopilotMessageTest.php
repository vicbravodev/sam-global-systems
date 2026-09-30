<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Access\Actions\SyncRolePermissions;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Infrastructure\AI\Agents\CopilotAgent;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

class SendCopilotMessageTest extends TestCase
{
    use CopilotFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AIMeterSeeder::class);
    }

    public function test_full_unit_report_answers_with_grounded_cards(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team);

        $response = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", [
                'content' => 'Dame el reporte de la unidad T-555',
            ])
            ->assertCreated()
            ->assertJsonPath('answer.intent', CopilotIntent::AssetReport->value)
            ->assertJsonPath('answer.context.resolved.asset_id', $asset->id);

        $types = collect($response->json('answer.blocks'))->pluck('type')->all();

        foreach (['asset', 'location', 'telemetry', 'fuel', 'bars'] as $expected) {
            $this->assertContains($expected, $types, "Falta la tarjeta {$expected}");
        }

        $fuel = collect($response->json('answer.blocks'))->firstWhere('type', 'fuel');
        $this->assertSame(90.0, (float) $fuel['current']);
        $this->assertCount(1, $fuel['refuels']);
        $this->assertCount(1, $fuel['suddenDrops']);

        $telemetry = collect($response->json('answer.blocks'))->firstWhere('type', 'telemetry');
        $this->assertSame('Encendido', collect($telemetry['readings'])->firstWhere('key', 'ignition')['value']);

        $this->assertStringContainsString('T555', $response->json('answer.content'));
        $this->assertSame(1, CopilotConversation::query()->where('user_id', $user->id)->count());
        $this->assertSame(2, CopilotMessage::query()->where('team_id', $team->id)->count());
    }

    public function test_where_is_question_returns_the_live_position(): void
    {
        [$user, $team] = $this->memberWithRole('monitorista');
        $this->truckWithTelemetry($team, 'R12');

        $response = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está el r12?'])
            ->assertCreated()
            ->assertJsonPath('answer.intent', CopilotIntent::AssetLocation->value);

        $location = collect($response->json('answer.blocks'))->firstWhere('type', 'location');
        $this->assertSame('Av. Insurgentes Sur, CDMX', $location['formattedLocation']);
        $this->assertSame('moving', $location['motion']);
        $this->assertCount(3, $location['trail']);
    }

    public function test_panic_kpis_count_only_panic_events_of_the_tenant(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team);
        $panic = EventType::factory()->create(['code' => 'panic_button', 'name' => 'Botón de pánico']);
        $other = EventType::factory()->create();

        $events = NormalizedEvent::factory()->count(3)->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'event_type_id' => $panic->id,
            'occurred_at' => now()->subHours(2),
        ]);
        NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'event_type_id' => $other->id,
        ]);
        Incident::factory()->create([
            'team_id' => $team->id,
            'related_event_id' => $events[0]->id,
            'opened_at' => now()->subHours(2),
            'acknowledged_at' => now()->subHours(2)->addSeconds(90),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => 'KPIs de los últimos botones de pánico'])
            ->assertCreated()
            ->assertJsonPath('answer.intent', CopilotIntent::PanicKpis->value);

        $kpis = collect($response->json('answer.blocks'))->firstWhere('type', 'kpis')['items'];
        $this->assertSame(3, collect($kpis)->firstWhere('label', 'Pánicos')['value']);
        $this->assertSame('1.5 min', collect($kpis)->firstWhere('label', 'Resp. media')['value']);
        $this->assertCount(3, collect($response->json('answer.blocks'))->firstWhere('type', 'events')['items']);
    }

    public function test_unit_question_without_a_unit_offers_a_picker_and_follow_ups_keep_the_unit(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team);

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Cuánto combustible tiene?'])
            ->assertCreated()
            ->assertJsonPath('answer.blocks.0.type', 'asset_picker')
            ->assertJsonPath('answer.blocks.0.options.0.id', $asset->id);

        $first = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está T555?'])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", [
                'content' => '¿y su combustible?',
                'conversation_id' => $first->json('conversation.id'),
            ])
            ->assertCreated()
            ->assertJsonPath('answer.intent', CopilotIntent::FuelReport->value)
            ->assertJsonPath('answer.context.resolved.asset_id', $asset->id)
            ->assertJsonPath('answer.blocks.0.type', 'fuel');
    }

    public function test_explicit_template_and_unit_from_the_ui_win_over_the_text(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team);
        EventMediaContext::factory()->videoClip()->create([
            'team_id' => $team->id,
            'normalized_event_id' => NormalizedEvent::factory()->create(['team_id' => $team->id, 'asset_id' => $asset->id])->id,
            'asset_id' => $asset->id,
            'media_url' => 'https://cdn.example.test/clip.mp4',
        ]);

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", [
                'content' => 'muéstrame lo último',
                'asset_id' => $asset->id,
                'intent' => CopilotIntent::AssetMedia->value,
            ])
            ->assertCreated()
            ->assertJsonPath('answer.blocks.0.type', 'media')
            ->assertJsonPath('answer.blocks.0.items.0.url', 'https://cdn.example.test/clip.mp4');
    }

    public function test_a_unit_pinned_in_the_composer_does_not_narrow_fleet_wide_questions(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $pinned = $this->truckWithTelemetry($team, 'T555');
        $other = $this->truckWithTelemetry($team, 'T777');
        $panic = EventType::factory()->create(['code' => 'panic_button']);
        NormalizedEvent::factory()->create(['team_id' => $team->id, 'asset_id' => $other->id, 'event_type_id' => $panic->id, 'occurred_at' => now()->subHour()]);

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", [
                'content' => 'KPIs de los últimos botones de pánico',
                'asset_id' => $pinned->id,
            ])
            ->assertCreated()
            ->assertJsonPath('answer.context.resolved.asset_id', null)
            ->assertJsonPath('answer.blocks.0.items.0.value', 1);

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => 'botones de pánico de la T555'])
            ->assertCreated()
            ->assertJsonPath('answer.context.resolved.asset_id', $pinned->id)
            ->assertJsonPath('answer.blocks.0.items.0.value', 0);
    }

    public function test_roles_without_media_access_get_a_permission_notice_instead_of_data(): void
    {
        [$user, $team] = $this->memberWithRole('analyst');
        $asset = $this->truckWithTelemetry($team);

        // A tenant role that can use Copilot and see units, but not camera media.
        app(SyncRolePermissions::class)->execute(
            Role::query()->where('code', 'analyst')->firstOrFail(),
            ['copilot.use', 'assets.view'],
        );

        $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", [
                'content' => 'última media',
                'asset_id' => $asset->id,
                'intent' => CopilotIntent::AssetMedia->value,
            ])
            ->assertCreated()
            ->assertJsonPath('answer.blocks.0.type', 'notice')
            ->assertJsonPath('answer.blocks.0.tone', 'warn');
    }

    public function test_every_answer_is_metered_idempotently_and_audited(): void
    {
        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        $response = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Cómo está la flota?'])
            ->assertCreated();

        $answerId = $response->json('answer.id');

        $this->assertDatabaseHas('usage_events', [
            'team_id' => $team->id,
            'event_key' => "copilot_queries:copilot:{$answerId}",
            'quantity' => 1,
        ]);
        // No LLM configured in tests: grounded answer, zero tokens billed.
        $this->assertSame(0, UsageEvent::query()->where('event_key', 'like', 'ai_tokens_%:copilot:%')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'copilot.query')->where('team_id', $team->id)->exists());
        $this->assertSame(1, $response->json('quota.used'));
    }

    public function test_agent_turn_records_tokens_cost_and_usage(): void
    {
        config([
            'ai.providers.openai.key' => 'test-key',
            'ai.pricing' => ['gpt-test' => ['input' => 1.0, 'output' => 4.0]],
        ]);

        CopilotAgent::fake([
            new ToolCall('c1', 'asset_location', ['asset_code' => 'T555']),
            new TextResponse(
                'La unidad **T555** va en ruta por Insurgentes Sur a 72 km/h.',
                new TextUsage(inputTokens: 1200, outputTokens: 300),
                new Meta(provider: 'openai', model: 'gpt-test'),
            ),
        ]);

        [$user, $team] = $this->memberWithRole('supervisor');
        $this->truckWithTelemetry($team);

        $response = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => '¿Dónde está T555?', 'channel' => 'bubble'])
            ->assertCreated()
            ->assertJsonPath('answer.content', 'La unidad **T555** va en ruta por Insurgentes Sur a 72 km/h.')
            ->assertJsonPath('answer.usage.inputTokens', 1200)
            ->assertJsonPath('answer.usage.outputTokens', 300)
            ->assertJsonPath('answer.usage.model', 'gpt-test')
            ->assertJsonPath('answer.tools.0.tool', 'asset_location');

        $this->assertEqualsWithDelta(0.0024, $response->json('answer.usage.cost'), 0.000001);

        $answerId = $response->json('answer.id');
        $this->assertDatabaseHas('usage_events', ['event_key' => "ai_tokens_in:copilot:{$answerId}", 'quantity' => 1200]);
        $this->assertDatabaseHas('usage_events', ['event_key' => "ai_tokens_out:copilot:{$answerId}", 'quantity' => 300]);
        $this->assertDatabaseHas('copilot_messages', ['id' => $answerId, 'channel' => 'bubble']);
    }

    public function test_feedback_is_stored_on_the_answer(): void
    {
        [$user, $team] = $this->memberWithRole('monitorista');

        $answer = $this->actingAs($user)
            ->postJson("/{$team->slug}/copilot/messages", ['content' => 'incidentes abiertos'])
            ->assertCreated()
            ->json('answer.id');

        $this->actingAs($user)
            ->putJson("/{$team->slug}/copilot/messages/{$answer}/feedback", ['rating' => -1])
            ->assertOk();

        $this->assertSame(-1, CopilotMessage::query()->findOrFail($answer)->feedback);
    }
}
