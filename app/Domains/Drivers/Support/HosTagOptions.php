<?php

namespace App\Domains\Drivers\Support;

/**
 * Etiquetas de Samsara listas para el selector: cada hija debajo de su
 * madre (elegir la madre incluye a las hijas, como en ResolveHosEnrollment),
 * hermanas por nombre, con lo que agrupan (tractos, choferes o ambos). Una
 * hija cuya madre no existe es raíz; un ciclo no se pierde ni se repite.
 */
final class HosTagOptions
{
    /**
     * @param  array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>  $tags
     * @return list<array{id: string, name: string, parentId: string|null, parentName: string|null, depth: int, kind: 'vehicle'|'driver'|'both'|'empty', vehicleCount: int, driverCount: int}>
     */
    public static function present(array $tags): array
    {
        $byId = [];

        foreach ($tags as $tag) {
            $byId[$tag['id']] ??= $tag;
        }

        $children = [];
        $roots = [];

        foreach ($byId as $tag) {
            $parent = $tag['parent_id'];

            if ($parent !== null && $parent !== $tag['id'] && isset($byId[$parent])) {
                $children[$parent][] = $tag['id'];
            } else {
                $roots[] = $tag['id'];
            }
        }

        $byName = fn (string $a, string $b): int => [strtolower($byId[$a]['name']), $a] <=> [strtolower($byId[$b]['name']), $b];
        usort($roots, $byName);

        $out = [];
        $seen = [];

        $walk = function (string $id, int $depth) use (&$walk, &$out, &$seen, $byId, $children, $byName): void {
            if (isset($seen[$id])) {
                return;
            }

            $seen[$id] = true;
            $tag = $byId[$id];
            $parent = $tag['parent_id'] !== null && isset($byId[$tag['parent_id']]) ? $byId[$tag['parent_id']] : null;
            $vehicles = count($tag['vehicle_ids']);
            $drivers = count($tag['driver_ids']);

            $out[] = [
                'id' => $tag['id'],
                'name' => $tag['name'],
                'parentId' => $depth === 0 ? null : $parent['id'] ?? null,
                'parentName' => $depth === 0 ? null : $parent['name'] ?? null,
                'depth' => $depth,
                'kind' => match (true) {
                    $vehicles > 0 && $drivers > 0 => 'both',
                    $vehicles > 0 => 'vehicle',
                    $drivers > 0 => 'driver',
                    default => 'empty',
                },
                'vehicleCount' => $vehicles,
                'driverCount' => $drivers,
            ];

            $kids = $children[$id] ?? [];
            usort($kids, $byName);

            foreach ($kids as $kid) {
                $walk($kid, $depth + 1);
            }
        };

        foreach ($roots as $root) {
            $walk($root, 0);
        }

        // Un ciclo (A→B→A) no tiene raíz: entra como raíz en el orden recibido.
        foreach ($byId as $tag) {
            $walk($tag['id'], 0);
        }

        return $out;
    }
}
