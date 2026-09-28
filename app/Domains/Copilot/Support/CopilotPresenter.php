<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use Carbon\CarbonImmutable;

/**
 * Shared shapes and Spanish labels for the Copilot cards.
 */
final class CopilotPresenter
{
    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        'active' => 'Activo',
        'inactive' => 'Inactivo',
        'offline' => 'Sin conexión',
        'alert' => 'Alerta',
        'critical' => 'Crítico',
        'maintenance' => 'Mantenimiento',
    ];

    /**
     * Speed (km/h) above which a unit counts as moving.
     */
    public const MOVING_SPEED_KPH = 5;

    /**
     * A position older than this is no longer "live".
     */
    public const STALE_AFTER_MINUTES = 30;

    public static function assetLabel(Asset $asset): string
    {
        return $asset->code ? "{$asset->code} · {$asset->name}" : (string) $asset->name;
    }

    public static function assetHref(string $teamSlug, int $assetId): string
    {
        return "/{$teamSlug}/assets/{$assetId}";
    }

    public static function incidentHref(string $teamSlug, int $incidentId): string
    {
        return "/{$teamSlug}/incidents/{$incidentId}";
    }

    public static function eventHref(string $teamSlug, int $eventId): string
    {
        return "/{$teamSlug}/events/{$eventId}";
    }

    public static function driverHref(string $teamSlug, int $driverId): string
    {
        return "/{$teamSlug}/drivers/{$driverId}";
    }

    public static function mapsUrl(float $latitude, float $longitude): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.$latitude.','.$longitude;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function location(?AssetLocationSnapshot $location): ?array
    {
        if ($location === null) {
            return null;
        }

        $latitude = (float) $location->latitude;
        $longitude = (float) $location->longitude;

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'formattedLocation' => $location->formatted_location,
            'speed' => $location->speed !== null ? round((float) $location->speed, 1) : null,
            'heading' => $location->heading !== null ? (int) $location->heading : null,
            'recordedAt' => $location->recorded_at->toIso8601String(),
            'mapsUrl' => self::mapsUrl($latitude, $longitude),
        ];
    }

    /**
     * "En ruta" / "Detenida" / "Sin señal" from the latest position.
     */
    public static function motionState(?AssetLocationSnapshot $location): string
    {
        if ($location === null || $location->recorded_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            return 'no_signal';
        }

        return (float) $location->speed > self::MOVING_SPEED_KPH ? 'moving' : 'stopped';
    }

    public static function motionLabel(string $state): string
    {
        return match ($state) {
            'moving' => 'En ruta',
            'stopped' => 'Detenida',
            default => 'Sin señal reciente',
        };
    }

    public static function describeAge(?string $iso): string
    {
        if ($iso === null) {
            return 'sin registro';
        }

        return CarbonImmutable::parse($iso)->locale('es')->diffForHumans();
    }
}
