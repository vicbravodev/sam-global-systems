/**
 * Iniciales para avatares: primera letra del primer y del último nombre
 * ("Ana María López" → "AL"). Con una sola palabra devuelve sus primeras
 * `singleWordLength` letras; con un nombre vacío, cadena vacía.
 */
export function getInitials(name: string, singleWordLength = 1): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    if (parts.length === 0) {
        return '';
    }

    if (parts.length === 1) {
        return parts[0].slice(0, singleWordLength).toUpperCase();
    }

    return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase();
}
