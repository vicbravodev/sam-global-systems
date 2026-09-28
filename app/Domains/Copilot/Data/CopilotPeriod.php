<?php

namespace App\Domains\Copilot\Data;

use Carbon\CarbonImmutable;

/**
 * Time window a question refers to ("hoy", "esta semana", "últimos 30 días").
 */
final readonly class CopilotPeriod
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $label,
        public int $days,
    ) {}

    /**
     * Resolve the window from free text. Defaults to the last 7 days, which
     * is what a manager usually means by "últimamente".
     */
    public static function fromPrompt(string $normalizedPrompt, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        if (preg_match('/\bhoy\b|\bturno\b/u', $normalizedPrompt)) {
            return new self($now->startOfDay(), $now, 'hoy', 1);
        }

        if (preg_match('/\bayer\b/u', $normalizedPrompt)) {
            $yesterday = $now->subDay();

            return new self($yesterday->startOfDay(), $yesterday->endOfDay(), 'ayer', 1);
        }

        if (preg_match('/(\d{1,3})\s*(dias|d)\b/u', $normalizedPrompt, $match)) {
            $days = max(1, min(90, (int) $match[1]));

            return new self($now->subDays($days), $now, "últimos {$days} días", $days);
        }

        if (preg_match('/(\d{1,2})\s*(horas|h)\b/u', $normalizedPrompt, $match)) {
            $hours = max(1, min(72, (int) $match[1]));

            return new self($now->subHours($hours), $now, "últimas {$hours} h", 1);
        }

        if (preg_match('/\bmes\b|\bmensual\b/u', $normalizedPrompt)) {
            return new self($now->subDays(30), $now, 'últimos 30 días', 30);
        }

        return new self($now->subDays(7), $now, 'últimos 7 días', 7);
    }
}
