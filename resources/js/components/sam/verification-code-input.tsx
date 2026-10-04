import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useRef } from 'react';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { cn } from '@/lib/utils';

export interface VerificationCodeInputProps {
    id?: string;
    /** Nombre del campo que recibe el código (Inertia `<Form>`). */
    name?: string;
    /** Dígitos del código (6 en SMS y 2FA). */
    length?: number;
    /** Controlado: omítelo y el campo guarda su propio valor. */
    value?: string;
    onChange?: (value: string) => void;
    /** Envía el `<form>` que lo contiene al completar el último dígito. */
    submitOnComplete?: boolean;
    disabled?: boolean;
    autoFocus?: boolean;
    'aria-invalid'?: boolean;
    'aria-label'?: string;
    className?: string;
}

/**
 * Código de verificación en casillas (SMS, 2FA): sólo dígitos, pegado del
 * código completo, autocompletado desde el SMS (`one-time-code`) y envío
 * automático opcional al terminar. Con 6 dígitos se agrupa 3 · 3 para leerlo
 * de un vistazo.
 */
export function VerificationCodeInput({
    id,
    name,
    length = 6,
    value,
    onChange,
    submitOnComplete = false,
    disabled,
    autoFocus,
    className,
    ...props
}: VerificationCodeInputProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const invalid = props['aria-invalid'] === true;
    const groups =
        length % 2 === 0 && length >= 6 ? [length / 2, length / 2] : [length];

    const slotClass = cn(
        'h-12 w-10 bg-surface-1 font-mono text-xl font-semibold text-fg-1 sm:w-11',
        invalid && 'border-destructive',
    );

    return (
        <div ref={containerRef} className={className}>
            <InputOTP
                id={id}
                name={name}
                maxLength={length}
                value={value}
                onChange={onChange}
                onComplete={() => {
                    if (submitOnComplete) {
                        containerRef.current?.closest('form')?.requestSubmit();
                    }
                }}
                pattern={REGEXP_ONLY_DIGITS}
                inputMode="numeric"
                autoComplete="one-time-code"
                disabled={disabled}
                autoFocus={autoFocus}
                aria-invalid={invalid || undefined}
                aria-label={
                    props['aria-label'] ?? `Código de ${length} dígitos`
                }
                containerClassName="gap-3"
            >
                {groups.map((size, groupIndex) => {
                    const offset = groups
                        .slice(0, groupIndex)
                        .reduce((sum, n) => sum + n, 0);

                    return (
                        <div key={offset} className="flex items-center gap-3">
                            {groupIndex > 0 ? (
                                <span
                                    aria-hidden
                                    className="size-1.5 rounded-full bg-fg-3/50"
                                />
                            ) : null}
                            <InputOTPGroup>
                                {Array.from({ length: size }, (_, index) => (
                                    <InputOTPSlot
                                        key={offset + index}
                                        index={offset + index}
                                        className={slotClass}
                                    />
                                ))}
                            </InputOTPGroup>
                        </div>
                    );
                })}
            </InputOTP>
        </div>
    );
}
