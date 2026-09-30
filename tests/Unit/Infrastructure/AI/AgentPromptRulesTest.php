<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Infrastructure\AI\Agents\CopilotAgent;
use App\Infrastructure\AI\Agents\EventClassifierAgent;
use App\Infrastructure\AI\Agents\MediaInspectorAgent;
use App\Infrastructure\AI\Middleware\CopilotStepGuard;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot-ish guard over the rules the prompts must keep: a rewrite that
 * drops one of them silently changes how every event is classified.
 */
class AgentPromptRulesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function classifierRules(): array
    {
        return [
            'spanish output' => ['MUST be written in'],
            'panic coercion' => ['a cancelled panic can'],
            'safety correlation' => ['harsh_driving_near_event'],
            'visual verdicts' => ['media_assessments'],
            'critical hard rule' => ['CRITICAL SEVERITY — HARD RULE'],
            'critical never downgraded' => ['never return "false_positive", "noise", "duplicate" or "pending_evidence"'],
            'pending evidence only when pending' => ['`context_signals.video_pending` is'],
            'confidence calibration' => ['Use 0.9+ only when several'],
            'rubric panic' => ['- panic_button:'],
            'rubric collision' => ['- collision / near_collision:'],
            'rubric rollover' => ['- rollover_protection'],
            'rubric tampering' => ['- tampering / camera_obstructed:'],
            'rubric after hours' => ['- after_hours_movement:'],
            'rubric suspicious stop' => ['- suspicious_stop:'],
            'rubric passenger' => ['- unauthorized_passenger:'],
            'rubric compliance' => ['no_seatbelt, mobile_usage'],
            'rubric unmapped' => ['- unmapped:'],
            'field local time' => ['occurred_at_local'],
            'field telemetry' => ['position_stale'],
            'field geofences' => ['`geofences`'],
            'field driver without identity' => ['operational risk only (no identity)'],
            'field operational profile' => ['`operational_profile`'],
            'field incidents' => ['prior_similar_count'],
            'operator feedback' => ['`recent_history.operator_feedback`'],
            'operator confirmation never downgraded' => ['"confirmed" verdict must NEVER be downgraded'],
        ];
    }

    #[DataProvider('classifierRules')]
    public function test_classifier_prompt_keeps_rule(string $needle): void
    {
        $this->assertStringContainsString($needle, (string) (new EventClassifierAgent)->instructions());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inspectorRules(): array
    {
        return [
            'spanish output' => ['MUST be written in natural Spanish'],
            'event type input' => ['event_context.event_type_code'],
            'camera side input' => ['`camera_side`'],
            'capture offset input' => ['`capture_offset_seconds`'],
            'road facing semantics' => ['Never infer passengers, driver state or cabin'],
            'cabin facing semantics' => ['Driver/cabin-facing images show'],
            'coerced panic is inconclusive' => ['possibly coerced panic is "inconclusive", NOT "contradicts_event"'],
            'passenger needs cabin camera' => ['A road-facing image is "inconclusive"'],
            'per type confirms' => ['WHAT "CONFIRMS" / "CONTRADICTS" MEANS PER EVENT TYPE'],
        ];
    }

    #[DataProvider('inspectorRules')]
    public function test_inspector_prompt_keeps_rule(string $needle): void
    {
        $this->assertStringContainsString($needle, (string) (new MediaInspectorAgent)->instructions());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function copilotRules(): array
    {
        return [
            'spanish output' => ['Respondes SIEMPRE en español de México'],
            'grounded on tools only' => ['Todo dato sale de tus herramientas'],
            'never invent' => ['Nunca inventes'],
            'cards already show detail' => ['no repitas listas'],
            'permission denial' => ['falta de permisos'],
        ];
    }

    #[DataProvider('copilotRules')]
    public function test_copilot_prompt_keeps_rule(string $needle): void
    {
        $this->assertStringContainsString($needle, (string) (new CopilotAgent(
            new CopilotTurnScope(1, 'acme', [], false, 'America/Mexico_City', CarbonImmutable::now()),
            [],
            [],
            new CopilotStepGuard(teamId: 1, maxTurnTokens: 60000),
        ))->instructions());
    }
}
