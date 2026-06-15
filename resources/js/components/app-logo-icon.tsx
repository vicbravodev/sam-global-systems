import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 40 40"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <rect
                x="1"
                y="1"
                width="38"
                height="38"
                rx="8"
                fill="currentColor"
            />
            {/* Glifo en knockout: usa el color de fondo de la página para
                contrastar con el rect (currentColor) en ambos temas. */}
            <g
                stroke="var(--sam-mark-fg, var(--background, #0B0B0C))"
                strokeWidth="2.2"
                strokeLinecap="round"
                strokeLinejoin="round"
                fill="none"
            >
                <path d="M13 14.5c0-1.9 1.6-3.5 3.5-3.5h7c1.9 0 3.5 1.6 3.5 3.5S25.4 18 23.5 18h-7C14.6 18 13 19.6 13 21.5S14.6 25 16.5 25h7c1.9 0 3.5 1.6 3.5 3.5" />
                <circle
                    cx="20"
                    cy="20"
                    r="1.3"
                    fill="var(--sam-mark-fg, var(--background, #0B0B0C))"
                    stroke="none"
                />
            </g>
        </svg>
    );
}
