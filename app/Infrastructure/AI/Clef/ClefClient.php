<?php

namespace App\Infrastructure\AI\Clef;

use App\Support\SystemLog;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo de Workers AI para los modelos de decisión Clef. Valida que
 * vuelva una respuesta por pregunta y, en `choice`, que la opción elegida sea
 * una de las pedidas: mejor fallar que inventar una clasificación.
 */
class ClefClient
{
    private const string ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/%s/ai/run/@cf/cloudflare/%s';

    /** cURL: "Operation timed out". */
    private const int CURL_TIMEOUT = 28;

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, array<string, mixed>>  $questions
     * @param  list<array{content_type: string, base64: string}>  $images
     */
    public function run(string $model, array $state, array $questions, array $images = []): ClefResponse
    {
        $payload = ['model' => $model, 'state' => $state, 'questions' => $questions];

        if ($images !== []) {
            $payload['images'] = $images;
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::withToken((string) config('services.cloudflare.auth_token'))
                ->acceptJson()
                ->timeout((int) config('ai.clef.timeout_seconds', 15))
                ->post(sprintf(self::ENDPOINT, (string) config('services.cloudflare.account_id'), $model), $payload);
        } catch (ConnectionException $e) {
            throw new ClefRequestFailedException($this->isTimeout($e) ? 'timeout' : 'connection', true);
        }

        $latencyMs = SystemLog::elapsedMs($startedAt);

        if ($response->failed()) {
            $status = $response->status();

            throw new ClefRequestFailedException('http_'.$status, $status === 429 || $status >= 500);
        }

        $body = $response->json();
        $result = is_array($body) && is_array($body['result'] ?? null) ? $body['result'] : $body;

        if (! is_array($result) || ! is_array($result['answers'] ?? null)) {
            throw new ClefRequestFailedException('malformed_response', false);
        }

        foreach ($questions as $id => $question) {
            $answer = $result['answers'][$id] ?? null;

            if (! is_array($answer) || ($answer['type'] ?? null) !== $question['type'] || ! $this->answerIsValid($question, $answer)) {
                throw new ClefRequestFailedException('malformed_response', false);
            }
        }

        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];

        return new ClefResponse(
            model: is_string($result['model'] ?? null) ? $result['model'] : $model,
            answers: $result['answers'],
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            latencyMs: $latencyMs,
        );
    }

    /**
     * Valida los campos que el pipeline lee de cada tipo de respuesta: un
     * `choice` con su opción y probabilidades numéricas, un `score` numérico
     * dentro de sus niveles y un `noul` entre 0 y 1.
     *
     * @param  array<string, mixed>  $question
     * @param  array<mixed>  $answer
     */
    private function answerIsValid(array $question, array $answer): bool
    {
        $criteria = (array) ($question['criteria'] ?? []);

        return match ($question['type']) {
            'choice' => array_key_exists((string) ($answer['choice'] ?? ''), $criteria)
                && $this->probabilitiesAreNumeric($answer['probabilities'] ?? null),
            'score' => is_numeric($answer['score'] ?? null)
                && (float) $answer['score'] >= 0.0
                && (float) $answer['score'] <= count($criteria) - 1,
            'noul' => is_numeric($answer['noul'] ?? null)
                && (float) $answer['noul'] >= 0.0
                && (float) $answer['noul'] <= 1.0,
            default => false,
        };
    }

    private function probabilitiesAreNumeric(mixed $probabilities): bool
    {
        if (! is_array($probabilities) || $probabilities === []) {
            return false;
        }

        foreach ($probabilities as $value) {
            if (! is_numeric($value)) {
                return false;
            }
        }

        return true;
    }

    private function isTimeout(ConnectionException $e): bool
    {
        $previous = $e->getPrevious();

        return $previous instanceof ConnectException
            && ($previous->getHandlerContext()['errno'] ?? null) === self::CURL_TIMEOUT;
    }
}
