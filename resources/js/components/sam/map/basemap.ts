import type {
    LayerSpecification,
    Map as MapLibreMap,
    StyleSpecification,
} from 'maplibre-gl';

/**
 * OpenFreeMap "positron": free vector tiles with no API key, and a quiet
 * style with few layers. We do not ship it as-is: every paint color is
 * replaced by the `--map-*` tokens of the current theme, so the basemap is the
 * UI's own neutral in light and in dark (no inverted-canvas filter).
 */
const STYLE_URL = 'https://tiles.openfreemap.org/styles/positron';

/** Layers the app adds on top of the basemap; the palette never touches them. */
export const SAM_LAYER_PREFIX = 'sam-';

type Role =
    | 'bg'
    | 'land'
    | 'park'
    | 'water'
    | 'building'
    | 'road'
    | 'roadMajor'
    | 'casing'
    | 'rail'
    | 'boundary'
    | 'label'
    | 'labelStrong'
    | 'labelWater'
    | 'halo'
    | 'shieldOpacity';

const ROLE_TOKENS: Record<Role, string> = {
    bg: '--map-bg',
    land: '--map-land',
    park: '--map-park',
    water: '--map-water',
    building: '--map-building',
    road: '--map-road',
    roadMajor: '--map-road-major',
    casing: '--map-road-casing',
    rail: '--map-rail',
    boundary: '--map-boundary',
    label: '--map-label',
    labelStrong: '--map-label-strong',
    labelWater: '--map-label-water',
    halo: '--map-label-halo',
    shieldOpacity: '--map-shield-opacity',
};

export type MapPalette = Record<Role, string | number>;

let baseStyle: Promise<StyleSpecification> | null = null;

/** The raw style, fetched once per page load and shared by every map. */
export function loadBaseStyle(): Promise<StyleSpecification> {
    baseStyle ??= fetch(STYLE_URL)
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Basemap style: HTTP ${response.status}`);
            }

            return response.json() as Promise<StyleSpecification>;
        })
        .catch((error: unknown) => {
            // Let the next map retry instead of caching the failure.
            baseStyle = null;

            throw error;
        });

    return baseStyle;
}

let probeCanvas: CanvasRenderingContext2D | null = null;

/**
 * A concrete `rgb()`/`rgba()` for any CSS color, including `var(--x)` and
 * oklch(): the MapLibre renderer understands neither. The browser resolves the
 * value on a probe element and a 1px canvas converts it to sRGB.
 */
export function resolveCssColor(value: string): string {
    const probe = document.createElement('span');
    probe.style.color = value;
    probe.style.display = 'none';
    document.body.appendChild(probe);
    const computed = getComputedStyle(probe).color;
    probe.remove();

    if (probeCanvas === null) {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        probeCanvas = canvas.getContext('2d', { willReadFrequently: true });
    }

    if (probeCanvas === null) {
        return computed;
    }

    probeCanvas.clearRect(0, 0, 1, 1);
    probeCanvas.fillStyle = computed;
    probeCanvas.fillRect(0, 0, 1, 1);
    const [r, g, b, a] = probeCanvas.getImageData(0, 0, 1, 1).data;

    return a === 255
        ? `rgb(${r}, ${g}, ${b})`
        : `rgba(${r}, ${g}, ${b}, ${(a / 255).toFixed(3)})`;
}

/** The current theme's basemap colors (reads the `.dark` class in effect). */
export function readPalette(): MapPalette {
    const root = getComputedStyle(document.documentElement);

    return Object.fromEntries(
        Object.entries(ROLE_TOKENS).map(([role, token]) => [
            role,
            role === 'shieldOpacity'
                ? Number.parseFloat(root.getPropertyValue(token)) || 1
                : resolveCssColor(`var(${token})`),
        ]),
    ) as MapPalette;
}

type PaintAssignment = [property: string, role: Role];

/** Which paint property of each positron layer takes which palette role. */
function assignmentsFor(layer: LayerSpecification): PaintAssignment[] {
    const id = layer.id;

    switch (layer.type) {
        case 'background':
            return [['background-color', 'bg']];
        case 'fill':
            if (id === 'water') {
                return [['fill-color', 'water']];
            }

            if (id === 'park' || id === 'landcover_wood') {
                return [['fill-color', 'park']];
            }

            if (id === 'building') {
                return [
                    ['fill-color', 'building'],
                    ['fill-outline-color', 'casing'],
                ];
            }

            if (id === 'road_area_pier') {
                return [['fill-color', 'bg']];
            }

            return [['fill-color', 'land']];
        case 'line':
            if (id === 'waterway') {
                return [['line-color', 'water']];
            }

            if (id.startsWith('boundary')) {
                return [['line-color', 'boundary']];
            }

            if (id.includes('dashline') || id === 'road_pier') {
                return [['line-color', 'bg']];
            }

            if (id.startsWith('railway')) {
                return [['line-color', 'rail']];
            }

            if (id.includes('casing')) {
                return [['line-color', 'casing']];
            }

            if (
                id === 'highway_major_inner' ||
                id === 'highway_motorway_inner' ||
                id === 'highway_motorway_bridge_inner'
            ) {
                return [['line-color', 'roadMajor']];
            }

            return [['line-color', 'road']];
        case 'symbol': {
            const paint = (layer.paint ?? {}) as Record<string, unknown>;
            const assignments: PaintAssignment[] = [];

            // Road shields are white sprites: dimmed so they do not glare
            // on the dark basemap.
            if (/shield/.test(id)) {
                return [
                    ['icon-opacity', 'shieldOpacity'],
                    ['text-opacity', 'shieldOpacity'],
                ];
            }

            if ('text-color' in paint) {
                assignments.push([
                    'text-color',
                    id.startsWith('water_name')
                        ? 'labelWater'
                        : /^label_(city|country|state)/.test(id)
                          ? 'labelStrong'
                          : 'label',
                ]);
            }

            if ('text-halo-color' in paint || 'text-color' in paint) {
                assignments.push(['text-halo-color', 'halo']);
            }

            return assignments;
        }
        default:
            return [];
    }
}

/** A copy of the base style painted with `palette`. */
export function themedStyle(
    base: StyleSpecification,
    palette: MapPalette,
): StyleSpecification {
    return {
        ...base,
        layers: base.layers.map((layer) => {
            const assignments = assignmentsFor(layer);

            if (assignments.length === 0) {
                return layer;
            }

            const paint: Record<string, unknown> = { ...(layer.paint ?? {}) };

            for (const [property, role] of assignments) {
                paint[property] = palette[role];
            }

            return { ...layer, paint } as LayerSpecification;
        }),
    };
}

/**
 * Repaint a live map for a theme switch in place: unlike `setStyle`, it
 * keeps the sources and layers the app added (trails, overlays).
 */
export function applyPalette(map: MapLibreMap, palette: MapPalette): void {
    for (const layer of map.getStyle().layers) {
        if (layer.id.startsWith(SAM_LAYER_PREFIX)) {
            continue;
        }

        for (const [property, role] of assignmentsFor(layer)) {
            map.setPaintProperty(layer.id, property, palette[role]);
        }
    }
}
