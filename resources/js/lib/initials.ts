/**
 * Iniciales para avatares: primera letra del primer y del último nombre
 * ("Ana María López" → "AL"). Con una sola palabra devuelve sus primeras
 * `singleWordLength` letras; con un nombre vacío, cadena vacía.
 */
export function getInitials(name: string, singleWordLength = 1): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    const [first] = parts;
    const last = parts.at(-1);

    if (!first || !last) {
        return '';
    }

    if (parts.length === 1) {
        return first.slice(0, singleWordLength).toUpperCase();
    }

    return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase();
}
