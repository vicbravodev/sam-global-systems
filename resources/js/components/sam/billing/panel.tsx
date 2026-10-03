import { formatCurrency } from '@/lib/format';

/** Importe con la moneda del backend ("1,234.50 MXN"). */
export function money(value: number, currency: string | null): string {
    return formatCurrency(value, currency?.toUpperCase() ?? null);
}
