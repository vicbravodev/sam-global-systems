<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Contracts\AI\MediaAssessmentAgent;
use App\Contracts\NullImplementations\NullEventEvaluationAgent;
use App\Contracts\NullImplementations\NullMediaAssessmentAgent;
use App\Infrastructure\AI\Agents\SdkEventEvaluationAgent;
use App\Infrastructure\AI\Agents\SdkMediaAssessmentAgent;
use Tests\TestCase;

/**
 * The SDK bindings are lazy singletons, so each test mutates config BEFORE
 * resolving the contracts. Configuration must be read from config (never
 * `env()` directly) so the bindings keep working under `config:cache`.
 */
class AIAgentBindingTest extends TestCase
{
    public function test_resolves_sdk_agents_when_default_provider_has_a_key(): void
    {
        config()->set('ai.default', 'openai');
        config()->set('ai.providers.openai.key', 'sk-test');

        $this->assertInstanceOf(SdkEventEvaluationAgent::class, app(EventEvaluationAgent::class));
        $this->assertInstanceOf(SdkMediaAssessmentAgent::class, app(MediaAssessmentAgent::class));
    }

    public function test_resolves_null_agents_when_provider_key_is_empty(): void
    {
        config()->set('ai.default', 'openai');
        config()->set('ai.providers.openai.key', '');

        $this->assertInstanceOf(NullEventEvaluationAgent::class, app(EventEvaluationAgent::class));
        $this->assertInstanceOf(NullMediaAssessmentAgent::class, app(MediaAssessmentAgent::class));
    }

    public function test_resolves_null_agents_when_provider_key_is_whitespace(): void
    {
        config()->set('ai.default', 'openai');
        config()->set('ai.providers.openai.key', '   ');

        $this->assertInstanceOf(NullEventEvaluationAgent::class, app(EventEvaluationAgent::class));
    }

    public function test_resolves_null_agents_when_default_provider_does_not_exist(): void
    {
        config()->set('ai.default', 'nonexistent-provider');

        $this->assertInstanceOf(NullEventEvaluationAgent::class, app(EventEvaluationAgent::class));
        $this->assertInstanceOf(NullMediaAssessmentAgent::class, app(MediaAssessmentAgent::class));
    }

    public function test_resolves_null_agents_when_default_is_empty(): void
    {
        config()->set('ai.default', '');

        $this->assertInstanceOf(NullEventEvaluationAgent::class, app(EventEvaluationAgent::class));
    }
}
