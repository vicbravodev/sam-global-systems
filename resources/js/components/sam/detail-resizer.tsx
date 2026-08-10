import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export interface DetailResizerProps {
    /** Min detail-pane width in px. */
    min?: number;
    /** Max detail-pane width in px. */
    max?: number;
    /** Default width when unset / after reset. */
    defaultWidth?: number;
    /** localStorage key for persisting the chosen width. */
    storageKey?: string;
    /** Extra classes merged onto the handle (e.g. to hide it on mobile). */
    className?: string;
}

/**
 * Drag handle for a two-pane detail layout. Mount it as the FIRST child of the
 * detail panel inside a container that has the `has-detail` class and a
 * `grid-template-columns: minmax(0,1fr) <detail>` track. Drag to resize, persist
 * to localStorage, double-click to reset. Below 1000px it clears the inline
 * template so the layout can stack via CSS. Keyboard: ArrowLeft/Right resize the
 * pane (step 10, or 50 with Shift).
 */
export function DetailResizer({
    min = 380,
    max = 980,
    defaultWidth = 600,
    storageKey = 'sam-detail-w',
    className,
}: DetailResizerProps) {
    const ref = useRef<HTMLDivElement>(null);

    const readWidth = () => {
        if (typeof localStorage === 'undefined') {
            return Math.max(min, Math.min(max, defaultWidth));
        }

        const stored = parseInt(localStorage.getItem(storageKey) ?? '', 10);
        const w = Number.isFinite(stored) ? stored : defaultWidth;

        return Math.max(min, Math.min(max, w));
    };

    const widthRef = useRef(readWidth());
    const [width, setWidth] = useState(widthRef.current);

    const setCurrentWidth = (w: number) => {
        widthRef.current = w;
        setWidth(w);
    };

    const apply = (root: HTMLElement | null, w: number) => {
        if (!root) {
            return;
        }

        if (typeof window === 'undefined') {
            return;
        }

        if (window.innerWidth < 1000) {
            root.style.gridTemplateColumns = '';

            return;
        }

        root.style.gridTemplateColumns = `minmax(0,1fr) ${w}px`;
    };

    useEffect(() => {
        const root = ref.current?.closest<HTMLElement>('.has-detail') ?? null;

        if (!root) {
            return;
        }

        apply(root, readWidth());

        const onResize = () => apply(root, readWidth());

        window.addEventListener('resize', onResize);

        return () => {
            window.removeEventListener('resize', onResize);
            root.style.gridTemplateColumns = '';
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onPointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
        e.preventDefault();

        const root = ref.current?.closest<HTMLElement>('.has-detail');
        const aside = ref.current?.parentElement;

        if (!root || !aside) {
            return;
        }

        const startX = e.clientX;
        const startW = aside.getBoundingClientRect().width;

        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        ref.current?.classList.add('dragging');

        const move = (ev: PointerEvent) => {
            const w = Math.max(
                min,
                Math.min(max, startW + (startX - ev.clientX)),
            );

            root.style.gridTemplateColumns = `minmax(0,1fr) ${w}px`;
        };

        const up = () => {
            const w = Math.round(aside.getBoundingClientRect().width);

            setCurrentWidth(w);
            localStorage.setItem(storageKey, String(w));
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            document.removeEventListener('pointercancel', up);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            ref.current?.classList.remove('dragging');
        };

        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
        document.addEventListener('pointercancel', up);
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
            return;
        }

        e.preventDefault();

        const step = e.shiftKey ? 50 : 10;
        const dir = e.key === 'ArrowRight' ? 1 : -1;
        const w = Math.max(min, Math.min(max, widthRef.current + dir * step));

        setCurrentWidth(w);
        apply(ref.current?.closest<HTMLElement>('.has-detail') ?? null, w);
        localStorage.setItem(storageKey, String(w));
    };

    const reset = () => {
        setCurrentWidth(defaultWidth);
        localStorage.setItem(storageKey, String(defaultWidth));
        apply(
            ref.current?.closest<HTMLElement>('.has-detail') ?? null,
            defaultWidth,
        );
    };

    return (
        <div
            ref={ref}
            onPointerDown={onPointerDown}
            onDoubleClick={reset}
            onKeyDown={onKeyDown}
            role="separator"
            aria-orientation="vertical"
            tabIndex={0}
            aria-valuemin={min}
            aria-valuemax={max}
            aria-valuenow={width}
            title="Arrastrar para redimensionar · doble clic para restablecer"
            className={cn(
                'absolute top-0 left-0 z-40 h-full w-2.5 -translate-x-1/2 cursor-col-resize touch-none before:absolute before:top-0 before:left-1/2 before:h-full before:w-px before:-translate-x-1/2 before:bg-border hover:before:w-[3px] hover:before:bg-primary motion-safe:before:transition-[background,width] motion-safe:before:duration-[--motion-fast] [&.dragging]:before:w-[3px] [&.dragging]:before:bg-primary',
                className,
            )}
        />
    );
}
