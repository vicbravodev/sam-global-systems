<?php

namespace App\Domains\Copilot\Tools\Sdk;

use Illuminate\Contracts\JsonSchema\JsonSchema;

final class FindAssetsSdkTool extends ArgumentedCopilotTool
{
    protected function extraSchema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Parte del número económico o del nombre, p. ej. "t5" o "kenworth".')->required(),
            'limit' => $schema->integer()->min(1)->max(10),
        ];
    }

    protected function extraRules(): array
    {
        return [
            'query' => ['required', 'string', 'min:1', 'max:40'],
            'limit' => ['nullable', 'integer', 'between:1,10'],
        ];
    }
}
