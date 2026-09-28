import { Link } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import { useSettingsNav } from '@/components/sam/settings/use-settings-nav';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

/**
 * Sub-navegación de Ajustes en dos grupos: "Tu cuenta" (lo personal) y
 * "Tu empresa" (lo que comparte todo el equipo). En escritorio es una
 * columna fija; en móvil la sustituye `SettingsNavSwitcher`.
 */
export function SettingsNav({ className }: { className?: string }) {
    const groups = useSettingsNav();

    return (
        <nav
            className={cn('flex flex-col gap-5', className)}
            aria-label="Ajustes"
            data-test="settings-nav"
        >
            {groups.map((group) => (
                <div key={group.title} className="flex flex-col gap-0.5">
                    <span className="sam-caps px-2.5 pb-1">{group.title}</span>
                    {group.items.map((item) => {
                        const Icon = item.icon;

                        return (
                            <Link
                                key={item.key}
                                href={item.href}
                                aria-current={item.active ? 'page' : undefined}
                                className={cn(
                                    'flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm transition-colors ease-(--ease-out) motion-safe:duration-[--motion-fast]',
                                    item.active
                                        ? 'bg-surface-3 font-medium text-fg-1'
                                        : 'text-fg-2 hover:bg-surface-2 hover:text-fg-1',
                                )}
                            >
                                <Icon
                                    className={cn(
                                        'size-4 shrink-0',
                                        item.active
                                            ? 'text-primary'
                                            : 'text-fg-3',
                                    )}
                                    aria-hidden
                                />
                                <span className="truncate">{item.title}</span>
                            </Link>
                        );
                    })}
                </div>
            ))}
        </nav>
    );
}

/**
 * Selector compacto de sección para pantallas estrechas: un botón con la
 * sección actual que despliega el mismo índice agrupado.
 */
export function SettingsNavSwitcher({ className }: { className?: string }) {
    const groups = useSettingsNav();
    const current = groups
        .flatMap((group) => group.items)
        .find((item) => item.active);
    const CurrentIcon = current?.icon;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    className={cn('w-full justify-between', className)}
                    data-test="settings-nav-switcher"
                >
                    <span className="flex min-w-0 items-center gap-2">
                        {CurrentIcon ? (
                            <CurrentIcon
                                className="size-4 shrink-0 text-primary"
                                aria-hidden
                            />
                        ) : null}
                        <span className="text-fg-3">Ajustes ·</span>
                        <span className="truncate">
                            {current?.title ?? 'Elige una sección'}
                        </span>
                    </span>
                    <ChevronDown className="size-4 shrink-0 text-fg-3" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-96 w-(--radix-dropdown-menu-trigger-width) overflow-y-auto"
            >
                {groups.map((group, index) => (
                    <DropdownMenuGroup key={group.title}>
                        {index > 0 ? <DropdownMenuSeparator /> : null}
                        <DropdownMenuLabel className="sam-caps">
                            {group.title}
                        </DropdownMenuLabel>
                        {group.items.map((item) => {
                            const Icon = item.icon;

                            return (
                                <DropdownMenuItem key={item.key} asChild>
                                    <Link href={item.href}>
                                        <Icon
                                            className="size-4 text-fg-3"
                                            aria-hidden
                                        />
                                        <span className="flex-1">
                                            {item.title}
                                        </span>
                                        {item.active ? (
                                            <Check className="size-4 text-primary" />
                                        ) : null}
                                    </Link>
                                </DropdownMenuItem>
                            );
                        })}
                    </DropdownMenuGroup>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
