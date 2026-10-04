import {
    Combobox,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
} from '@headlessui/react';
import { Check, ChevronDown } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import InputError from '@/components/input-error';
import {
    applyNationalInput,
    DEFAULT_PHONE_COUNTRY,
    matchesCountry,
    parsePhoneDraft,
    phoneCountryOption,
    phoneCountryOptions,
    phoneValidity,
    toE164,
} from '@/lib/phone';
import type { CountryCode, PhoneDraft } from '@/lib/phone';
import { TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';

const COUNTRY_OPTIONS = phoneCountryOptions();

const countryOf = (code: CountryCode) =>
    COUNTRY_OPTIONS.find((option) => option.country === code) ??
    phoneCountryOption(code);

export interface PhoneInputProps {
    id?: string;
    /** Con `name`, envía el E.164 en un input oculto (Inertia `<Form>`). */
    name?: string;
    /** Valor inicial en E.164 (no controlado: remonta con `key` para resetear). */
    defaultValue?: string | null;
    /** Recibe el E.164 en cada cambio ('' si está vacío). */
    onChange?: (e164: string) => void;
    defaultCountry?: CountryCode;
    required?: boolean;
    disabled?: boolean;
    autoFocus?: boolean;
    /** Error del servidor: pinta el control en rojo. */
    'aria-invalid'?: boolean;
    /** Sin `<label htmlFor>` asociado, nombre accesible del número. */
    'aria-label'?: string;
    className?: string;
}

/**
 * Teléfono con selector de país: el usuario escribe el número nacional con
 * formato automático y el control entrega siempre E.164 (`+528117658890`),
 * el contrato de `App\Support\PhoneNumber`. Pegar `+1 …` o `00 52 …` cambia
 * el país solo; la validación por país (libphonenumber) avisa al salir del
 * campo si faltan dígitos o la lada no corresponde.
 */
export function PhoneInput({
    id,
    name,
    defaultValue,
    onChange,
    defaultCountry = DEFAULT_PHONE_COUNTRY,
    required,
    disabled,
    autoFocus,
    className,
    ...props
}: PhoneInputProps) {
    const [draft, setDraft] = useState<PhoneDraft>(() =>
        parsePhoneDraft(defaultValue, defaultCountry),
    );
    const [touched, setTouched] = useState(false);
    const [query, setQuery] = useState('');
    const nationalRef = useRef<HTMLInputElement>(null);

    const e164 = toE164(draft);
    const validity = phoneValidity(draft);
    const country = countryOf(draft.country);
    const localError =
        touched && validity === 'incomplete'
            ? `Faltan dígitos para un número de ${country.name}.`
            : touched && validity === 'invalid'
              ? `No parece un número de ${country.name}. Revisa el país y la lada.`
              : undefined;
    const invalid = props['aria-invalid'] === true || localError !== undefined;

    const update = (next: PhoneDraft) => {
        setDraft(next);
        onChange?.(toE164(next));
    };

    const handleNational = (event: ChangeEvent<HTMLInputElement>) => {
        const deleting = event.target.value.length < draft.national.length;

        update(
            applyNationalInput(event.target.value, draft.country, {
                format: !deleting,
            }),
        );
    };

    const filtered = COUNTRY_OPTIONS.filter((option) =>
        matchesCountry(option, query),
    );

    return (
        <div className={cn('flex flex-col gap-1.5', className)}>
            <div
                className={cn(
                    'flex h-9 w-full rounded-md border border-input shadow-xs transition-[color,box-shadow]',
                    'focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                    invalid &&
                        'border-destructive ring-destructive/20 focus-within:border-destructive focus-within:ring-destructive/20 dark:ring-destructive/40',
                    disabled && 'cursor-not-allowed opacity-50',
                )}
            >
                <Combobox
                    value={draft.country}
                    onChange={(code: CountryCode | null) => {
                        if (code !== null) {
                            update({ country: code, national: draft.national });
                            // Headless UI reenfoca el selector en el frame
                            // siguiente al elegir; dos frames después el foco
                            // pasa al número para seguir escribiendo.
                            requestAnimationFrame(() =>
                                requestAnimationFrame(() =>
                                    nationalRef.current?.focus(),
                                ),
                            );
                        }
                    }}
                    onClose={() => setQuery('')}
                    disabled={disabled}
                >
                    <div className="relative flex w-24 shrink-0 items-center border-r border-input">
                        <ComboboxInput
                            aria-label="País y lada"
                            placeholder="Buscar"
                            className="h-full w-full min-w-0 bg-transparent pr-6 pl-2.5 text-base tabular-nums outline-none md:text-sm"
                            displayValue={(code: CountryCode) =>
                                `${countryOf(code).flag} ${countryOf(code).dialCode}`
                            }
                            onChange={(event) => setQuery(event.target.value)}
                            onFocus={(event) => event.target.select()}
                        />
                        <ChevronDown
                            className="pointer-events-none absolute right-1.5 size-3.5 text-fg-3"
                            aria-hidden
                        />
                    </div>
                    <ComboboxOptions
                        anchor="bottom start"
                        transition
                        className={cn(
                            'z-50 w-72 overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md [--anchor-gap:6px] [--anchor-max-height:280px] empty:invisible',
                            'origin-top transition duration-150 ease-out data-closed:scale-[0.97] data-closed:opacity-0',
                        )}
                    >
                        {filtered.map((option) => (
                            <ComboboxOption
                                key={option.country}
                                value={option.country}
                                className="flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none select-none data-focus:bg-accent data-focus:text-accent-foreground"
                            >
                                <span aria-hidden>{option.flag}</span>
                                <span className="min-w-0 flex-1 truncate">
                                    {option.name}
                                </span>
                                <span className="text-fg-3 tabular-nums">
                                    {option.dialCode}
                                </span>
                                {option.country === draft.country ? (
                                    <Check className="size-3.5" aria-hidden />
                                ) : null}
                            </ComboboxOption>
                        ))}
                        {filtered.length === 0 ? (
                            <div className="px-2 py-1.5 text-sm text-fg-3">
                                Sin países con «{query}»
                            </div>
                        ) : null}
                    </ComboboxOptions>
                </Combobox>

                <div className="relative flex min-w-0 flex-1 items-center">
                    <input
                        ref={nationalRef}
                        id={id}
                        type="tel"
                        inputMode="tel"
                        autoComplete="tel"
                        value={draft.national}
                        onChange={handleNational}
                        onBlur={() => setTouched(true)}
                        required={required}
                        disabled={disabled}
                        autoFocus={autoFocus}
                        aria-invalid={invalid || undefined}
                        aria-label={props['aria-label']}
                        placeholder={
                            country.country === 'MX' ? '81 1234 5678' : 'Número'
                        }
                        className="h-full w-full min-w-0 bg-transparent px-3 text-base tabular-nums outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed md:text-sm"
                    />
                    {validity === 'valid' ? (
                        <Check
                            className={cn(
                                'pointer-events-none absolute right-2.5 size-4',
                                TONE_TEXT.ok,
                            )}
                            aria-label="Número válido"
                        />
                    ) : null}
                </div>
            </div>

            {name !== undefined ? (
                <input type="hidden" name={name} value={e164} />
            ) : null}
            <InputError message={localError} className="text-xs" />
        </div>
    );
}
