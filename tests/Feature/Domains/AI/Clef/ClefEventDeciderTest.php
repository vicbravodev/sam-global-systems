<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Infrastructure\AI\Clef\ClefEventDecider;
use App\Infrastructure\AI\Clef\ClefRequestFailedException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefEventDeciderTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloudflare.account_id' => 'acc', 'services.cloudflare.auth_token' => 'tok']);
    }

    public function test_maps_answers_to_decision_with_cost_and_risk(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('noise', inputTokens: 1_000_000))]);

        $decision = app(ClefEventDecider::class)->decide('clef', ['media_assessments' => [['x']], 'telemetry' => []], []);

        $this->assertSame('noise', $decision->classification);
        $this->assertSame(0.8, $decision->classificationProbabilities['noise']);
        $this->assertSame(0.75, $decision->riskScore);
        $this->assertSame(0.72, $decision->needsHumanProbability);
        $this->assertNull($decision->mediaAnswers);
        $this->assertSame(0.24, $decision->costEstimate);
        Http::assertSent(fn (Request $r): bool => ! array_key_exists('media_assessments', $r['state']) && ! isset($r['questions']['media_result']));
    }

    public function test_context_window_overflow_retries_with_half_the_images(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::sequence()
            ->push(['success' => false, 'errors' => [['message' => 'exceeded this model context window limit']]], 413)
            ->push($this->clefResponse('real_event', $this->mediaAnswers())),
        ]);

        $decision = app(ClefEventDecider::class)->decide('clef', [], $this->images(4));

        $this->assertSame(2, $decision->imagesSent);
        $this->assertSame('confirms_event', $decision->mediaAnswers['media_result']['choice'] ?? null);
        $sent = Http::recorded()->map(fn (array $pair) => count($pair[0]->data()['images'] ?? []))->all();
        $this->assertSame([4, 2], $sent);
        $this->assertSystemLogged('ai.clef_shadow.image_skipped', fn (array $c) => $c['reason'] === 'context_window' && $c['calc']['images_after'] === 2);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_keeps_halving_down_to_text_only(): void
    {
        $tooBig = ['success' => false, 'errors' => [['message' => 'context window']]];
        Http::fake(['api.cloudflare.com/*' => Http::sequence()
            ->push($tooBig, 413)
            ->push($tooBig, 413)
            ->push($tooBig, 413)
            ->push($this->clefResponse('noise')),
        ]);

        $decision = app(ClefEventDecider::class)->decide('clef', [], $this->images(4));

        $this->assertSame(0, $decision->imagesSent);
        $this->assertNull($decision->mediaAnswers);
        $last = Http::recorded()->last()[0];
        $this->assertArrayNotHasKey('images', $last->data());
        $this->assertArrayNotHasKey('media_result', $last['questions']);
    }

    public function test_text_only_overflow_is_still_a_failure(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 413)]);

        $this->expectExceptionObject(new ClefRequestFailedException('http_413', false));

        app(ClefEventDecider::class)->decide('clef', [], []);
    }

    /**
     * @return list<array{content_type: string, base64: string}>
     */
    private function images(int $count): array
    {
        return array_fill(0, $count, ['content_type' => 'image/jpeg', 'base64' => 'AA==']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mediaAnswers(): array
    {
        $media = [
            'media_result' => ['type' => 'choice', 'choice' => 'confirms_event', 'probabilities' => ['confirms_event' => 0.9], 'confidence' => 0.9],
            'persons_visible' => ['type' => 'choice', 'choice' => '1', 'probabilities' => ['1' => 0.9], 'confidence' => 0.9],
        ];
        foreach (['driver_visible', 'passenger_detected', 'visible_threat', 'cabin_appears_normal', 'vehicle_moving'] as $signal) {
            $media[$signal] = ['type' => 'choice', 'choice' => 'no_visible', 'probabilities' => ['no_visible' => 1.0], 'confidence' => 1.0];
        }

        return $media;
    }

    public function test_with_images_asks_media_questions_and_keeps_their_answers(): void
    {
        $media = [
            'media_result' => ['type' => 'choice', 'choice' => 'confirms_event', 'probabilities' => ['confirms_event' => 0.9], 'confidence' => 0.9],
            'persons_visible' => ['type' => 'choice', 'choice' => '1', 'probabilities' => ['1' => 0.9], 'confidence' => 0.9],
        ];
        foreach (['driver_visible', 'passenger_detected', 'visible_threat', 'cabin_appears_normal', 'vehicle_moving'] as $signal) {
            $media[$signal] = ['type' => 'choice', 'choice' => 'no_visible', 'probabilities' => ['no_visible' => 1.0], 'confidence' => 1.0];
        }
        Http::fake(['api.cloudflare.com/*' => Http::response($this->clefResponse('real_event', $media))]);

        $decision = app(ClefEventDecider::class)->decide('clef-flash', [], [['content_type' => 'image/jpeg', 'base64' => 'AA==']]);

        $this->assertSame(1, $decision->imagesSent);
        $this->assertSame('confirms_event', $decision->mediaAnswers['media_result']['choice']);
        $this->assertSame('no_visible', $decision->mediaAnswers['visible_threat']['choice']);
    }
}
