import { DEMO_HREF } from '@/components/landing/copy';
import { PrimaryButton } from '@/components/landing/primary-button';

export function CtaSection() {
    return (
        <section className="bg-brand-mist">
            <div className="mx-auto max-w-7xl px-5 py-24 sm:px-8 lg:py-32">
                <h2 className="max-w-4xl text-4xl font-semibold tracking-poster text-balance lg:text-6xl">
                    Tu próxima emergencia no debería depender de quién esté
                    despierto.
                </h2>
                <div className="mt-12 flex flex-col gap-8 sm:flex-row sm:items-center sm:gap-12">
                    <PrimaryButton href={DEMO_HREF} large>
                        Pedir una demo
                    </PrimaryButton>
                    <p className="max-w-sm text-base leading-relaxed text-brand-ink-2">
                        Te la mostramos con las alertas de tu propia operación,
                        en una llamada de 30 minutos.
                    </p>
                </div>
            </div>
        </section>
    );
}
