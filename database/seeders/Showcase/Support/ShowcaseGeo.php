<?php

namespace Database\Seeders\Showcase\Support;

/**
 * Geografía del showcase: puntos reales del área metropolitana de Monterrey
 * y sus corredores carreteros (donde opera el tenant de prueba), para que
 * mapa, direcciones y geocercas se vean creíbles.
 */
final class ShowcaseGeo
{
    /**
     * @var array<int, array{0: string, 1: float, 2: float}> [dirección, lat, lng]
     */
    public const PLACES = [
        ['Av. Constitución 400, Centro, Monterrey, N.L.', 25.6682, -100.3101],
        ['Av. Miguel Alemán km 8, Guadalupe, N.L.', 25.6981, -100.2227],
        ['Carretera Miguel Alemán km 22, Apodaca, N.L.', 25.7412, -100.1478],
        ['Blvd. Aeropuerto, Apodaca, N.L.', 25.7735, -100.1073],
        ['Parque Industrial Stiva, Apodaca, N.L.', 25.7801, -100.2012],
        ['Av. Universidad 1500, San Nicolás de los Garza, N.L.', 25.7302, -100.3101],
        ['Autopista Monterrey–Saltillo km 12, Santa Catarina, N.L.', 25.6860, -100.4675],
        ['Av. Lincoln 5000, Mitras Poniente, Monterrey, N.L.', 25.7187, -100.3857],
        ['Carretera a Laredo km 18, Escobedo, N.L.', 25.8254, -100.3169],
        ['Libramiento Noreste km 30, Escobedo, N.L.', 25.8398, -100.2734],
        ['Av. Ruiz Cortines 3500, Guadalupe, N.L.', 25.6948, -100.2610],
        ['Carretera Nacional km 268, Santiago, N.L.', 25.4892, -100.1745],
        ['Av. Eugenio Garza Sada 2501, Tecnológico, Monterrey, N.L.', 25.6515, -100.2895],
        ['Parque Industrial Finsa, Guadalupe, N.L.', 25.6693, -100.2011],
        ['Carretera a Reynosa km 15, Juárez, N.L.', 25.6502, -100.0958],
        ['Av. Díaz Ordaz, San Pedro Garza García, N.L.', 25.6720, -100.3806],
        ['Carretera Mezquital–Santa Rosa, Apodaca, N.L.', 25.7528, -100.2368],
        ['Autopista Monterrey–Nuevo Laredo km 45, Ciénega de Flores, N.L.', 25.9519, -100.1702],
    ];

    /**
     * Geocercas del tenant: base, CEDIS, clientes y zonas de riesgo.
     *
     * @var array<int, array{code: string, name: string, category: string, lat: float, lng: float, radius: int}>
     */
    public const GEOFENCES = [
        ['code' => 'base-apodaca', 'name' => 'Base Apodaca (patio principal)', 'category' => 'base', 'lat' => 25.7528, 'lng' => -100.2368, 'radius' => 600],
        ['code' => 'cedis-escobedo', 'name' => 'CEDIS Escobedo', 'category' => 'distribution_center', 'lat' => 25.8254, 'lng' => -100.3169, 'radius' => 450],
        ['code' => 'cliente-stiva', 'name' => 'Cliente — Parque Industrial Stiva', 'category' => 'client_site', 'lat' => 25.7801, 'lng' => -100.2012, 'radius' => 350],
        ['code' => 'cliente-finsa', 'name' => 'Cliente — Parque Finsa Guadalupe', 'category' => 'client_site', 'lat' => 25.6693, 'lng' => -100.2011, 'radius' => 350],
        ['code' => 'riesgo-libramiento', 'name' => 'Zona de riesgo — Libramiento Noreste', 'category' => 'risk_zone', 'lat' => 25.8398, 'lng' => -100.2734, 'radius' => 2500],
        ['code' => 'ruta-saltillo', 'name' => 'Ruta restringida — Autopista a Saltillo nocturno', 'category' => 'restricted_route', 'lat' => 25.6860, 'lng' => -100.4675, 'radius' => 3000],
    ];

    /**
     * @return array{0: string, 1: float, 2: float}
     */
    public static function place(ShowcaseRandom $random): array
    {
        return $random->pick(self::PLACES);
    }

    /**
     * Desplaza un punto unos cientos de metros (grado ≈ 111 km).
     *
     * @return array{0: float, 1: float}
     */
    public static function jitter(ShowcaseRandom $random, float $lat, float $lng, float $meters = 800): array
    {
        $delta = $meters / 111_000;

        return [
            round($lat + $random->float(-$delta, $delta, 6), 6),
            round($lng + $random->float(-$delta, $delta, 6), 6),
        ];
    }

    /**
     * Distancia aproximada en metros (equirectangular: sobra para < 50 km).
     */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $x = deg2rad($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $y = deg2rad($lat2 - $lat1);

        return (int) round(sqrt($x * $x + $y * $y) * 6_371_000);
    }
}
