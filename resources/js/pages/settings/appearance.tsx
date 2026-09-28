import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import { FormCard } from '@/components/sam/field';
import {
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';

const OPTIONS: {
    value: Appearance;
    icon: LucideIcon;
    label: string;
    help: string;
}[] = [
    {
        value: 'light',
        icon: Sun,
        label: 'Claro',
        help: 'Fondo blanco. Mejor con mucha luz.',
    },
    {
        value: 'dark',
        icon: Moon,
        label: 'Oscuro',
        help: 'Fondo negro. Cansa menos en turnos de noche.',
    },
    {
        value: 'system',
        icon: Monitor,
        label: 'Automático',
        help: 'Sigue la configuración de tu equipo.',
    },
];

export default function AppearancePage() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <>
            <Head title="Apariencia" />
            <SettingsPage
                title="Apariencia"
                description="Cómo se ve SAM en este navegador. No afecta a los demás."
            >
                <SettingsSection
                    title="Tema"
                    description="El cambio se aplica al instante."
                >
                    <FormCard>
                        <div
                            role="radiogroup"
                            aria-label="Tema"
                            className="grid grid-cols-1 gap-2 sm:grid-cols-3"
                        >
                            {OPTIONS.map(
                                ({ value, icon: Icon, label, help }) => {
                                    const selected = appearance === value;

                                    return (
                                        <button
                                            key={value}
                                            type="button"
                                            role="radio"
                                            aria-checked={selected}
                                            onClick={() =>
                                                updateAppearance(value)
                                            }
                                            className={cn(
                                                'flex items-start gap-3 rounded-md border p-3 text-left transition-colors ease-(--ease-out) motion-safe:duration-[--motion-fast]',
                                                selected
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-border hover:bg-surface-2',
                                            )}
                                        >
                                            <Icon
                                                className={cn(
                                                    'mt-0.5 size-4 shrink-0',
                                                    selected
                                                        ? 'text-primary'
                                                        : 'text-fg-3',
                                                )}
                                                aria-hidden
                                            />
                                            <span className="min-w-0">
                                                <span className="block text-sm font-medium text-fg-1">
                                                    {label}
                                                </span>
                                                <span className="block text-xs text-fg-3">
                                                    {help}
                                                </span>
                                            </span>
                                        </button>
                                    );
                                },
                            )}
                        </div>
                    </FormCard>
                </SettingsSection>
            </SettingsPage>
        </>
    );
}

AppearancePage.layout = {
    breadcrumbs: [
        {
            title: 'Apariencia',
            href: editAppearance(),
        },
    ],
};
