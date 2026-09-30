<?php

namespace App\Domains\Copilot\Tools\Sdk;

use App\Domains\Copilot\Tools\RankAssetsTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;

final class RankAssetsSdkTool extends ArgumentedCopilotTool
{
    /** Ranks the whole fleet: narrowing to one unit makes no ranking. */
    protected function acceptsAssetCode(): bool
    {
        return false;
    }

    protected function extraSchema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()->enum(array_keys(RankAssetsTool::METRICS))->description('fuel_used_pct = % de tanque consumido; distance_km; idle_hours = horas de ralentí (motor encendido detenido); incidents; events; panics.')->required(),
            'order' => $schema->string()->enum(['desc', 'asc'])->description('desc = los que más; asc = los que menos.'),
            'limit' => $schema->integer()->min(1)->max(10),
            'event_type' => $schema->string()->description('Código de tipo de evento cuando metric=events (p. ej. harsh_brake).'),
        ];
    }

    protected function extraRules(): array
    {
        return [
            'metric' => ['required', Rule::in(array_keys(RankAssetsTool::METRICS))],
            'order' => ['nullable', Rule::in(['desc', 'asc'])],
            'limit' => ['nullable', 'integer', 'between:1,10'],
            'event_type' => ['nullable', 'string', 'max:60'],
        ];
    }
}
