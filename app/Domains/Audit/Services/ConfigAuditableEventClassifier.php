<?php

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Contracts\AuditableEventClassifier;
use App\Domains\Audit\Data\AuditableEventDescriptor;
use App\Domains\Audit\Enums\AuditCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * Default classifier: looks up the dispatched event FQCN in the
 * `audit.events` allowlist and resolves a descriptor from the payload.
 *
 * Returning null means the event is not on the allowlist and should
 * be ignored by the wildcard listener.
 */
class ConfigAuditableEventClassifier implements AuditableEventClassifier
{
    /**
     * @param  array<class-string, array{category: string, action: string, tenant_via: string}>  $allowlist
     */
    public function __construct(
        private readonly array $allowlist,
    ) {}

    public function classify(string $eventName, array $payload): ?AuditableEventDescriptor
    {
        if (! isset($this->allowlist[$eventName])) {
            return null;
        }

        $config = $this->allowlist[$eventName];
        $eventInstance = $payload[0] ?? null;

        if (! is_object($eventInstance)) {
            return null;
        }

        $teamId = $this->resolveTeamId($eventInstance, $config['tenant_via'] ?? 'none');
        [$aggregateType, $aggregateId] = $this->resolveAggregate($eventInstance, $config['tenant_via'] ?? 'none');

        return new AuditableEventDescriptor(
            eventName: $eventName,
            category: AuditCategory::from($config['category']),
            action: $config['action'],
            teamId: $teamId,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            payloadJson: $this->serializePayload($eventInstance),
            signature: $this->buildSignature($eventName, $eventInstance, $teamId, $aggregateId),
        );
    }

    private function resolveTeamId(object $event, string $strategy): ?int
    {
        if ($strategy === 'none') {
            return null;
        }

        if (str_starts_with($strategy, 'property:')) {
            $property = substr($strategy, strlen('property:'));

            return $this->readIntProperty($event, $property);
        }

        if (str_starts_with($strategy, 'model:')) {
            $property = substr($strategy, strlen('model:'));

            $value = $this->readProperty($event, $property);

            if ($value instanceof Model) {
                $teamId = $value->getAttribute('team_id');

                return is_int($teamId) ? $teamId : (is_numeric($teamId) ? (int) $teamId : null);
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveAggregate(object $event, string $strategy): array
    {
        if (str_starts_with($strategy, 'model:')) {
            $property = substr($strategy, strlen('model:'));
            $value = $this->readProperty($event, $property);

            if ($value instanceof Model) {
                $key = $value->getKey();

                return [
                    $value::class,
                    is_numeric($key) ? (int) $key : null,
                ];
            }
        }

        return [null, null];
    }

    private function readIntProperty(object $event, string $property): ?int
    {
        $value = $this->readProperty($event, $property);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function readProperty(object $event, string $property): mixed
    {
        if (! property_exists($event, $property) && ! isset($event->{$property})) {
            return null;
        }

        try {
            return $event->{$property} ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePayload(object $event): array
    {
        $public = get_object_vars($event);
        $serialized = [];

        foreach ($public as $key => $value) {
            $serialized[$key] = $this->normalizeValue($value);
        }

        return $serialized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return [
                '__model' => $value::class,
                'id' => $value->getKey(),
                'team_id' => $value->getAttribute('team_id'),
            ];
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[$k] = $this->normalizeValue($v);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return ['__object' => $value::class];
        }

        return $value;
    }

    private function buildSignature(string $eventName, object $event, ?int $teamId, ?int $aggregateId): string
    {
        $base = $eventName.':'.($teamId ?? 'system').':'.($aggregateId ?? 'na');

        // Include a stable hash of the public payload so identical replays
        // collapse but distinct payloads remain distinguishable.
        $payloadHash = substr(
            hash('sha256', json_encode($this->serializePayload($event), JSON_THROW_ON_ERROR)),
            0,
            16,
        );

        return $base.':'.$payloadHash;
    }
}
