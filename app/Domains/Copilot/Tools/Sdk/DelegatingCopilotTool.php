<?php

namespace App\Domains\Copilot\Tools\Sdk;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Data\CopilotTurnScope;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Support\AssetResolver;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Stringable;

/**
 * Exposes one of the existing domain `CopilotTool`s to the agent. The model
 * names the unit by code; the unit is resolved only inside the turn's tenant.
 */
class DelegatingCopilotTool extends SdkCopilotTool
{
    public function __construct(
        CopilotTurnScope $scope,
        CopilotTurnCollector $collector,
        protected readonly CopilotToolDefinition $definition,
        protected readonly AssetResolver $assets,
        protected readonly Container $container,
    ) {
        parent::__construct($scope, $collector);
    }

    public function name(): string
    {
        return $this->definition->name;
    }

    public function description(): Stringable|string
    {
        return $this->definition->description;
    }

    public function permission(): ?string
    {
        return $this->definition->permission;
    }

    public function intent(): CopilotIntent
    {
        return $this->definition->intent;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_code' => $this->definition->needsAsset
                ? $schema->string()->description('Número económico o nombre de la unidad, p. ej. T555.')->required()
                : $schema->string()->description('Opcional: limitar a una unidad.'),
            'from' => $schema->string()->description('Inicio ISO-8601 con zona horaria. Omite para últimos 7 días.'),
            'to' => $schema->string()->description('Fin ISO-8601 con zona horaria. Omite para ahora.'),
            'category' => $schema->string()->enum(array_column(AssetCategory::cases(), 'value'))->description('Opcional: categoría de unidad.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'asset_code' => [$this->definition->needsAsset ? 'required' : 'nullable', 'string', 'max:40'],
            'category' => ['nullable', Rule::enum(AssetCategory::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function execute(array &$args, string $toolCallId): CopilotToolResult
    {
        $asset = null;

        if (! empty($args['asset_code'])) {
            $asset = $this->assets->resolveByCode($this->scope->teamId, (string) $args['asset_code']) ?? throw new CopilotAssetNotFound;
        }

        $args['__asset_id'] = $asset?->id;
        $args['__period_days'] = $args['__period']->days;

        $context = new CopilotToolContext(
            teamId: $this->scope->teamId,
            teamSlug: $this->scope->teamSlug,
            permissions: $this->scope->permissions,
            period: $args['__period'],
            asset: $asset,
            category: isset($args['category']) ? AssetCategory::from($args['category']) : null,
            isSuperAdmin: $this->scope->isSuperAdmin,
            arguments: array_filter($args, fn (string $key) => ! str_starts_with($key, '__'), ARRAY_FILTER_USE_KEY),
        );

        return $this->container->make($this->definition->toolClass)->run($context);
    }
}
