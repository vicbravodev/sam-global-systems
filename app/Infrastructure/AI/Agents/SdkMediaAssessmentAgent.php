<?php

namespace App\Infrastructure\AI\Agents;

use App\Contracts\AI\MediaAssessmentAgent;
use App\Domains\AI\Data\MediaAssessmentInput;
use App\Domains\AI\Data\MediaAssessmentOutput;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Support\ModelPricing;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use RuntimeException;
use Throwable;

/**
 * Production `MediaAssessmentAgent` backed by the Laravel AI SDK
 * (`composer require laravel/ai`). Bound by `AIServiceProvider` whenever
 * `config('ai.default')` resolves to a configured provider; otherwise the
 * Null implementation continues to handle the contract.
 */
class SdkMediaAssessmentAgent implements MediaAssessmentAgent
{
    public function __construct(
        private readonly MediaInspectorAgent $inspector,
        private readonly ModelPricing $pricing,
    ) {}

    public function assess(MediaAssessmentInput $input): MediaAssessmentOutput
    {
        $payload = json_encode($input->toArray(), JSON_THROW_ON_ERROR);
        $attachments = $this->buildAttachments($input);

        $startedAt = hrtime(true);

        try {
            $response = $this->inspector->prompt($payload, attachments: $attachments);
        } catch (Throwable $exception) {
            throw new RuntimeException('Laravel AI SDK media invocation failed: '.$exception->getMessage(), previous: $exception);
        }

        $latencyMs = (int) intdiv(hrtime(true) - $startedAt, 1_000_000);

        $structured = $this->parseStructuredResponse($response->text);

        // Pricing keys on the raw provider model id; when `meta` is absent
        // the cost resolves to 0.0 rather than failing the assessment.
        return new MediaAssessmentOutput(
            result: MediaAssessmentResult::from($structured['result']),
            confidenceScore: (float) $structured['confidence_score'],
            summaryText: (string) ($structured['summary_text'] ?? ''),
            extractedSignals: (array) ($structured['extracted_signals'] ?? []),
            modelUsed: 'laravel-ai-sdk:'.($response->meta?->model ?? 'media-inspector'),
            inputTokens: (int) $response->usage->promptTokens,
            outputTokens: (int) $response->usage->completionTokens,
            latencyMs: $latencyMs,
            costEstimate: $this->pricing->estimateCost(
                $response->meta?->model,
                (int) $response->usage->promptTokens,
                (int) $response->usage->completionTokens,
            ),
        );
    }

    /**
     * @return array<int, Image|Document>
     */
    private function buildAttachments(MediaAssessmentInput $input): array
    {
        if ($input->storagePath === null) {
            return [];
        }

        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($input->storagePath)) {
            return [];
        }

        $mime = strtolower((string) $input->mimeType);

        return match (true) {
            str_starts_with($mime, 'image/') => [Image::fromStorage($input->storagePath)],
            default => [Document::fromStorage($input->storagePath)],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseStructuredResponse(string $text): array
    {
        $trimmed = trim($text);

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($trimmed, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('SDK media response was not valid JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! isset($decoded['result'], $decoded['confidence_score'])) {
            throw new RuntimeException('SDK media response missing required fields (result, confidence_score)');
        }

        return $decoded;
    }
}
