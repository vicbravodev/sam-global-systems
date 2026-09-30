import type { ImgHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type BrandImageProps = Omit<
    ImgHTMLAttributes<HTMLImageElement>,
    'src' | 'alt' | 'children'
> & {
    alt?: string;
};

/**
 * Logo completo de SAM (emblema + "SAM" + "Sistema Automatizado de
 * Monitoreo"), el del landing oficial. Para espacios con aire: login,
 * pantallas de error, portada. En barras y botones va `AppLogoIcon`.
 */
export default function AppLogo({
    className,
    alt = 'SAM — Sistema Automatizado de Monitoreo',
    ...props
}: BrandImageProps) {
    return (
        <>
            <img
                src="/images/brand/sam-logo.png"
                alt={alt}
                draggable={false}
                {...props}
                className={cn('w-auto object-contain dark:hidden', className)}
            />
            <img
                src="/images/brand/sam-logo-dark.png"
                alt=""
                aria-hidden="true"
                draggable={false}
                {...props}
                className={cn(
                    'hidden w-auto object-contain dark:block',
                    className,
                )}
            />
        </>
    );
}
