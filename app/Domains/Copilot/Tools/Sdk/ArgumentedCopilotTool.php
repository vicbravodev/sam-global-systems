<?php

namespace App\Domains\Copilot\Tools\Sdk;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * A delegating tool that takes arguments of its own on top of the shared
 * ones (asset_code, from, to, category). The domain tool reads them from
 * `CopilotToolContext::$arguments`, already validated.
 */
abstract class ArgumentedCopilotTool extends DelegatingCopilotTool
{
    /**
     * @return array<string, Type> extra JSON-schema args
     */
    abstract protected function extraSchema(JsonSchema $schema): array;

    /**
     * @return array<string, mixed> extra validation rules
     */
    abstract protected function extraRules(): array;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return parent::schema($schema) + $this->extraSchema($schema);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return parent::rules() + $this->extraRules();
    }
}
