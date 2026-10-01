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
use Illuminate\JsonSchema\Types\Type;
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
     * Whether the tool can be narrowed to one unit by `asset_code`. Tools that
     * search or rank the whole fleet opt out, so a guessed code is never
     * validated nor resolved (it would answer "unidad no encontrada").
     */
    protected function acceptsAssetCode(): bool
    {
        return true;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return array_filter([
            'asset_code' => ! $this->acceptsAssetCode() ? null : ($this->definition->needsAsset
                ? $schema->string()->description('Número económico o nombre de la unidad, p. ej. T555.')->required()
                : $schema->string()->description('Opcional: limitar a una unidad.')),
            'from' => $this->acceptsPeriod() ? $schema->string()->description('Inicio ISO-8601 con zona horaria. Omite para últimos 7 días.') : null,
            'to' => $this->acceptsPeriod() ? $schema->string()->description('Fin ISO-8601 con zona horaria. Omite para ahora.') : null,
            'category' => $schema->string()->enum(array_column(AssetCategory::cases(), 'value'))->description('Opcional: categoría de unidad.'),
        ], fn ($type) => $type !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return array_filter([
            'asset_code' => $this->acceptsAssetCode() ? [$this->definition->needsAsset ? 'required' : 'nullable', 'string', 'max:40'] : null,
            'category' => ['nullable', Rule::enum(AssetCategory::class)],
        ], fn ($rules) => $rules !== null);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function execute(array &$args, string $toolCallId): CopilotToolResult
    {
        $asset = null;

        $assetCode = $args['asset_code'] ?? null;

        // "0" es un código de unidad válido: sólo null/'' significan "sin unidad".
        if ($this->acceptsAssetCode() && is_string($assetCode) && $assetCode !== '') {
            $asset = $this->assets->resolveByCode($this->scope->teamId, $assetCode) ?? throw new CopilotAssetNotFound;
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
