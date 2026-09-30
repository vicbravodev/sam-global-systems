<?php

namespace App\Domains\Copilot\Tools\Sdk;

use Illuminate\Contracts\JsonSchema\JsonSchema;

final class SearchEventsSdkTool extends ArgumentedCopilotTool
{
    protected function extraSchema(JsonSchema $schema): array
    {
        return [
            'event_type' => $schema->string()->description('Código de tipo de evento, p. ej. harsh_brake, speeding, panic_button.'),
            'severity' => $schema->string()->description('Código de severidad: low, medium, high o critical.'),
            'limit' => $schema->integer()->min(1)->max(25)->description('Cuántos eventos recientes listar (10 por defecto).'),
        ];
    }

    protected function extraRules(): array
    {
        return [
            'event_type' => ['nullable', 'string', 'max:60'],
            'severity' => ['nullable', 'string', 'max:30'],
            'limit' => ['nullable', 'integer', 'between:1,25'],
        ];
    }
}
