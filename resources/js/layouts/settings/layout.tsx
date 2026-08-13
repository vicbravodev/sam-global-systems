import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { SettingsNav } from '@/components/sam/settings-nav';
import { Separator } from '@/components/ui/separator';

export default function SettingsLayout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-6">
            <Heading
                title="Ajustes"
                description="Tu cuenta y la configuración del tenant en un solo lugar"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-56">
                    <SettingsNav />
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
