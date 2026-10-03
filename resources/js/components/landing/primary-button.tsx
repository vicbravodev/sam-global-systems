import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { dashboard } from '@/routes';

export interface PrimaryButtonProps {
    href: ReturnType<typeof dashboard> | string;
    children: ReactNode;
    large?: boolean;
    inertia?: boolean;
}

export function PrimaryButton({
    href,
    children,
    large,
    inertia,
}: PrimaryButtonProps) {
    const className = cn(
        'inline-flex items-center justify-center rounded-full bg-brand-teal font-medium whitespace-nowrap text-white shadow-[0_8px_20px_-8px_rgba(0,128,159,0.6)] transition-[background-color,transform] duration-200 hover:bg-brand-petrol focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-teal active:scale-[0.97]',
        large ? 'h-12 px-7 text-md' : 'h-10 px-5 text-base',
    );

    return inertia ? (
        <Link href={href} className={className}>
            {children}
        </Link>
    ) : (
        <a
            href={typeof href === 'string' ? href : href.url}
            className={className}
        >
            {children}
        </a>
    );
}
