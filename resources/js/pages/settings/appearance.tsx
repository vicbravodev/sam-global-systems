import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import { FormCard } from '@/components/sam/field';
import { RadioCard, RadioCardGroup } from '@/components/sam/radio-card-group';
import {
    SettingsPage,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
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
                        <RadioCardGroup
                            label="Tema"
                            className="grid grid-cols-1 gap-2 sm:grid-cols-3"
                        >
                            {OPTIONS.map(({ value, icon, label, help }) => (
                                <RadioCard
                                    key={value}
                                    selected={appearance === value}
                                    onSelect={() => updateAppearance(value)}
                                    icon={icon}
                                    label={label}
                                    description={help}
                                />
                            ))}
                        </RadioCardGroup>
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
