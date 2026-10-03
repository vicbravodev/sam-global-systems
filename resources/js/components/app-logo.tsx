import type { ImgHTMLAttributes } from 'react';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

type BrandImageProps = Omit<
    ImgHTMLAttributes<HTMLImageElement>,
    'src' | 'alt' | 'children'
> & {
    alt?: string;
};

/** Intrinsic size of both logo PNGs (light and dark share it). */
const LOGO_WIDTH = 400;
const LOGO_HEIGHT = 338;

/**
 * Logo completo de SAM (emblema + "SAM" + "Sistema Automatizado de
 * Monitoreo"), el del landing oficial. Para espacios con aire: login,
 * pantallas de error, portada. En barras y botones va `AppLogoIcon`.
 *
 * Una sola imagen cuya fuente sigue el tema resuelto (la elección del
 * usuario, no sólo la del sistema): con dos `<img>` y una oculta por CSS el
 * navegador descargaba los dos PNG (~83 kB cada uno).
 */
export default function AppLogo({
    className,
    alt = 'SAM — Sistema Automatizado de Monitoreo',
    ...props
}: BrandImageProps) {
    const { resolvedAppearance } = useAppearance();

    return (
        <img
            src={
                resolvedAppearance === 'dark'
                    ? '/images/brand/sam-logo-dark.png'
                    : '/images/brand/sam-logo.png'
            }
            alt={alt}
            width={LOGO_WIDTH}
            height={LOGO_HEIGHT}
            decoding="async"
            draggable={false}
            {...props}
            className={cn('w-auto object-contain', className)}
        />
    );
}
