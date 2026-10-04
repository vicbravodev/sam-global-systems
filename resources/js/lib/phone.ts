import {
    AsYouType,
    getCountries,
    getCountryCallingCode,
    parsePhoneNumberFromString,
    validatePhoneNumberLength,
} from 'libphonenumber-js/min';
import type { CountryCode } from 'libphonenumber-js/min';

export type { CountryCode };

/** País por defecto de captura: la operación de SAM es mexicana. */
export const DEFAULT_PHONE_COUNTRY: CountryCode = 'MX';

/** Países que se listan primero en el selector (el resto va por nombre). */
const PRIORITY_COUNTRIES: CountryCode[] = ['MX', 'US', 'CA', 'GT', 'CO'];

export interface PhoneDraft {
    country: CountryCode;
    /** Número nacional tal como lo escribió el usuario (con o sin formato). */
    national: string;
}

export interface CountryOption {
    country: CountryCode;
    name: string;
    dialCode: string;
    flag: string;
}

const onlyDigits = (value: string): string => value.replace(/\D/g, '');

/** Bandera emoji a partir del código ISO (indicadores regionales). */
export function countryFlag(country: string): string {
    return String.fromCodePoint(
        ...[...country.toUpperCase()].map(
            (char) => 0x1f1a5 + char.charCodeAt(0),
        ),
    );
}

export function dialCode(country: CountryCode): string {
    return `+${getCountryCallingCode(country)}`;
}

/**
 * Descompone un valor guardado (E.164) en país + número nacional para
 * prellenar el control. Un valor que no se puede interpretar se conserva
 * tal cual en el país por defecto para no perder lo capturado.
 */
export function parsePhoneDraft(
    value: string | null | undefined,
    fallback: CountryCode = DEFAULT_PHONE_COUNTRY,
): PhoneDraft {
    const raw = (value ?? '').trim();

    if (raw === '') {
        return { country: fallback, national: '' };
    }

    const parsed = parsePhoneNumberFromString(raw, fallback);

    if (parsed?.country) {
        return {
            country: parsed.country,
            national: parsed.formatNational(),
        };
    }

    return { country: fallback, national: raw };
}

/**
 * Interpreta lo que el usuario escribe o pega en el campo nacional. Si trae
 * lada internacional (`+1 …`, `00 52 …`) cambia el país solo; si pega la
 * lada del país sin `+` (`52 81 1765 8890`) la quita. Con `format` (al
 * escribir, no al borrar) aplica el formato del país mientras se teclea.
 */
export function applyNationalInput(
    input: string,
    country: CountryCode,
    { format = true }: { format?: boolean } = {},
): PhoneDraft {
    const trimmed = input.trim().replace(/^00/, '+');

    if (trimmed.startsWith('+')) {
        const typer = new AsYouType();
        typer.input(trimmed);
        const detected = typer.getCountry();

        if (detected === undefined) {
            return { country, national: trimmed };
        }

        const national = typer.getNumber()?.nationalNumber ?? '';

        return {
            country: detected,
            national: format
                ? new AsYouType(detected).input(national)
                : national,
        };
    }

    // libphonenumber reconoce una lada del país pegada sin '+' y la separa
    // del número nacional; sólo se aplica con un número ya completo.
    const digits = onlyDigits(trimmed);
    const parsed = parsePhoneNumberFromString(digits, country);
    const pastedWithCode =
        parsed?.isValid() === true &&
        parsed.nationalNumber !== digits &&
        digits.startsWith(getCountryCallingCode(country));
    const national = pastedWithCode ? parsed.nationalNumber : digits;

    if (!format && !pastedWithCode) {
        return { country, national: trimmed };
    }

    return { country, national: new AsYouType(country).input(national) };
}

/**
 * Valor canónico E.164 (el contrato del backend, `App\Support\PhoneNumber`):
 * `+` + lada + número, sin separadores. Vacío si no se capturó nada.
 */
export function toE164({ country, national }: PhoneDraft): string {
    const digits = onlyDigits(national);

    if (digits === '') {
        return '';
    }

    const parsed = parsePhoneNumberFromString(national, country);

    return parsed?.number ?? `+${getCountryCallingCode(country)}${digits}`;
}

export type PhoneValidity = 'empty' | 'valid' | 'incomplete' | 'invalid';

export function phoneValidity({
    country,
    national,
}: PhoneDraft): PhoneValidity {
    const digits = onlyDigits(national);

    if (digits === '') {
        return 'empty';
    }

    const parsed = parsePhoneNumberFromString(national, country);

    if (parsed?.isValid()) {
        return 'valid';
    }

    return validatePhoneNumberLength(national, country) === 'TOO_SHORT'
        ? 'incomplete'
        : 'invalid';
}

/** Muestra un E.164 guardado en formato legible (`+52 81 1765 8890`). */
export function formatPhone(value: string | null | undefined): string {
    const raw = (value ?? '').trim();
    const parsed = raw === '' ? undefined : parsePhoneNumberFromString(raw);

    return parsed?.formatInternational() ?? raw;
}

export function phoneCountryOption(
    country: CountryCode,
    locale = 'es-MX',
): CountryOption {
    const names = new Intl.DisplayNames([locale], { type: 'region' });

    return {
        country,
        name: names.of(country) ?? country,
        dialCode: dialCode(country),
        flag: countryFlag(country),
    };
}

export function phoneCountryOptions(locale = 'es-MX'): CountryOption[] {
    const toOption = (country: CountryCode) =>
        phoneCountryOption(country, locale);

    const rest = getCountries()
        .filter((country) => !PRIORITY_COUNTRIES.includes(country))
        .map(toOption)
        .sort((a, b) => a.name.localeCompare(b.name, locale));

    return [...PRIORITY_COUNTRIES.map(toOption), ...rest];
}

/** Búsqueda del selector: nombre sin acentos, ISO o lada (`52`, `+52`). */
export function matchesCountry(option: CountryOption, query: string): boolean {
    const normalize = (value: string) =>
        value
            .normalize('NFD')
            .replace(/\p{Diacritic}/gu, '')
            .toLowerCase();
    const needle = normalize(query.trim());

    if (needle === '') {
        return true;
    }

    const needleDigits = onlyDigits(needle);

    return (
        normalize(option.name).includes(needle) ||
        option.country.toLowerCase() === needle ||
        (needleDigits !== '' &&
            option.dialCode.slice(1).startsWith(needleDigits))
    );
}
