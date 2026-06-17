import { useEffect, useRef, useState } from 'react';

/**
 * Scroll-reveal helper for marketing surfaces. Toggles `visible` once the
 * element enters the viewport (one-shot). Honors `prefers-reduced-motion`:
 * reduced-motion users get content rendered visible immediately, no transform.
 *
 * Uses IntersectionObserver (never a scroll listener) so it stays cheap and
 * does not re-render on every frame.
 */
export function useReveal<T extends HTMLElement = HTMLDivElement>() {
    const ref = useRef<T | null>(null);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        const reduce = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        // Reduced-motion users get content shown immediately via the
        // consumer's `motion-reduce:*` classes, so skip the observer entirely.
        const node = ref.current;

        if (reduce || !node) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        setVisible(true);
                        observer.disconnect();
                    }
                }
            },
            { threshold: 0.15, rootMargin: '0px 0px -10% 0px' },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return { ref, visible };
}
