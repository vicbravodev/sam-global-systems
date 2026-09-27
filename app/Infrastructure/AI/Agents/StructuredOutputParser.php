<?php

namespace App\Infrastructure\AI\Agents;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Tolerant decoding + normalization shared by the SDK agent wrappers.
 *
 * Both agents declare a JSON schema (`HasStructuredOutput`), so providers
 * with native structured output return a `StructuredAgentResponse`. Providers
 * or fakes that answer with plain text still go through a lenient JSON parser
 * that strips Markdown fences and surrounding prose. Values are clamped and
 * unknown enum values degrade to a safe default instead of failing the run.
 */
final class StructuredOutputParser
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(AgentResponse $response, string $errorPrefix): array
    {
        if ($response instanceof StructuredAgentResponse && $response->structured !== []) {
            return $response->structured;
        }

        return self::decodeText((string) $response->text, $errorPrefix);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeText(string $text, string $errorPrefix): array
    {
        $candidate = trim($text);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $candidate, $matches) === 1) {
            $candidate = trim($matches[1]);
        }

        if (! str_starts_with($candidate, '{')) {
            $start = strpos($candidate, '{');
            $end = strrpos($candidate, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $candidate = substr($candidate, $start, $end - $start + 1);
            }
        }

        try {
            $decoded = json_decode($candidate, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException($errorPrefix.' was not valid JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException($errorPrefix.' was not valid JSON: expected an object');
        }

        return $decoded;
    }

    /**
     * Confidence in 0..1. Models occasionally answer on a 0..100 scale: any
     * value in (1, 100] is treated as a percentage.
     */
    public static function confidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $number = (float) $value;

        if ($number > 1.0 && $number <= 100.0) {
            $number /= 100.0;
        }

        return self::clamp($number, 0.0, 1.0);
    }

    public static function clamp(mixed $value, float $min, float $max): float
    {
        if (! is_numeric($value)) {
            return max($min, min($max, 0.0));
        }

        return max($min, min($max, (float) $value));
    }

    /**
     * Accepts either a map (`{"speed": 90}`) or the schema's list of
     * `{name, value}` pairs and returns a flat map.
     *
     * @return array<string, mixed>
     */
    public static function keyValueMap(mixed $value, string $nameKey = 'name', string $valueKey = 'value'): array
    {
        if (! is_array($value)) {
            return [];
        }

        if (! array_is_list($value)) {
            return $value;
        }

        $map = [];

        foreach ($value as $entry) {
            if (is_array($entry) && isset($entry[$nameKey]) && is_scalar($entry[$nameKey])) {
                $map[(string) $entry[$nameKey]] = $entry[$valueKey] ?? null;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : (string) json_encode($item),
            $value,
        ));
    }
}
