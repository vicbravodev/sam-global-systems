<?php

namespace App\Support;

use Throwable;

/**
 * Fallo definitivo de un job con su contexto de dominio (ids del recurso).
 * Además de la línea genérica `queue.job.failed` (AutomaticSystemLog), deja
 * `{dominio}.{job}.failed` para filtrar por módulo.
 */
final class JobFailureReporter
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function report(string $jobClass, Throwable $e, array $context = []): void
    {
        // Corre dentro de `failed()` de los jobs: SystemLog nunca lanza al escribir.
        SystemLog::failed(self::codeFor($jobClass), reason: 'exception', input: ['job' => $jobClass] + $context, error: $e);
    }

    public static function codeFor(string $jobClass): string
    {
        $job = self::snake((string) preg_replace('/Job$/', '', class_basename($jobClass)));
        $domain = preg_match('/^App\\\\Domains\\\\([^\\\\]+)\\\\/', $jobClass, $match) === 1
            ? self::snake($match[1])
            : 'app';

        return "{$domain}.{$job}.failed";
    }

    /**
     * `AI` → `ai`, `TenantConfig` → `tenant_config`, `ApplyAIProfile` →
     * `apply_ai_profile` (Str::snake daría `a_i`).
     */
    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name));
    }
}
