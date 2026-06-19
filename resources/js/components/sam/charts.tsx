import { useId } from 'react';

/**
 * Dependency-free SVG chart primitives, ported from the SAM design system
 * (src/Charts.jsx). All colors come from OKLCH tokens; pass a CSS var string
 * (e.g. 'var(--chart-1)') as `color`. Charts scale to their container width.
 */

export interface SparkAreaProps {
    data: number[];
    width?: number;
    height?: number;
    color?: string;
    fill?: boolean;
}

export function SparkArea({
    data,
    width = 300,
    height = 60,
    color = 'var(--accent)',
    fill = true,
}: SparkAreaProps) {
    const uid = useId();

    if (!data || data.length < 2) {
        return null;
    }

    const max = Math.max(...data);
    const min = Math.min(...data);
    const range = max - min || 1;

    const pts = data.map((v, i) => {
        const x = (i / (data.length - 1)) * (width - 2) + 1;
        const y = height - 3 - ((v - min) / range) * (height - 8);

        return [x, y] as const;
    });

    const line = pts
        .map((p) => `${p[0].toFixed(1)},${p[1].toFixed(1)}`)
        .join(' ');
    const last = pts[pts.length - 1];

    return (
        <svg
            width="100%"
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            preserveAspectRatio="none"
            className="block"
            aria-hidden="true"
        >
            {fill && (
                <>
                    <defs>
                        <linearGradient id={uid} x1="0" y1="0" x2="0" y2="1">
                            <stop
                                offset="0%"
                                stopColor={color}
                                stopOpacity="0.28"
                            />
                            <stop
                                offset="100%"
                                stopColor={color}
                                stopOpacity="0"
                            />
                        </linearGradient>
                    </defs>
                    <polygon
                        fill={`url(#${uid})`}
                        points={`1,${height - 1} ${line} ${width - 1},${height - 1}`}
                    />
                </>
            )}
            <polyline
                fill="none"
                stroke={color}
                strokeWidth="1.6"
                points={line}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <circle cx={last[0]} cy={last[1]} r="2.6" fill={color} />
        </svg>
    );
}

export interface BarsProps {
    data: number[];
    labels?: string[];
    width?: number;
    height?: number;
    color?: string;
    valueFmt?: (v: number) => string | number;
}

export function Bars({
    data,
    labels,
    width = 460,
    height = 180,
    color = 'var(--chart-1)',
    valueFmt = (v) => v,
}: BarsProps) {
    const max = Math.max(...data, 1);
    const pad = 22;
    const bw = (width - pad) / data.length;

    return (
        <svg
            width="100%"
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className="block"
        >
            {[0, 0.5, 1].map((t) => (
                <line
                    key={t}
                    x1={pad}
                    x2={width}
                    y1={(height - 20) * t + 4}
                    y2={(height - 20) * t + 4}
                    stroke="var(--border)"
                    strokeWidth="1"
                />
            ))}
            {data.map((v, i) => {
                const h = (v / max) * (height - 30);
                const x = pad + i * bw + bw * 0.18;
                const w = bw * 0.64;
                const y = height - 20 - h;

                return (
                    <g key={i}>
                        <rect
                            x={x}
                            y={4}
                            width={w}
                            height={height - 24}
                            rx="2"
                            fill="var(--surface-3)"
                            opacity="0.5"
                        />
                        <rect
                            x={x}
                            y={y}
                            width={w}
                            height={h}
                            rx="2"
                            fill={color}
                        >
                            <title>{String(valueFmt(v))}</title>
                        </rect>
                        {labels && (
                            <text
                                x={x + w / 2}
                                y={height - 6}
                                textAnchor="middle"
                                fill="var(--fg-3)"
                                fontSize="10"
                                fontFamily="var(--font-mono)"
                            >
                                {labels[i]}
                            </text>
                        )}
                    </g>
                );
            })}
        </svg>
    );
}

export interface LineSeries {
    data: number[];
    color: string;
}

export interface LineChartProps {
    series: LineSeries[];
    labels?: string[];
    width?: number;
    height?: number;
}

export function LineChart({
    series,
    labels,
    width = 520,
    height = 200,
}: LineChartProps) {
    const all = series.flatMap((s) => s.data);
    const max = Math.max(...all, 1);
    const min = Math.min(...all, 0);
    const range = max - min || 1;
    const padL = 28;
    const padB = 18;
    const W = width - padL;
    const H = height - padB - 6;

    const xy = (data: number[]) =>
        data.map((v, i) => {
            const x = padL + (i / (data.length - 1)) * W;
            const y = 6 + H - ((v - min) / range) * H;

            return [x, y] as const;
        });

    return (
        <svg
            width="100%"
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className="block"
        >
            {[0, 0.25, 0.5, 0.75, 1].map((t) => (
                <g key={t}>
                    <line
                        x1={padL}
                        x2={width}
                        y1={6 + H * t}
                        y2={6 + H * t}
                        stroke="var(--border)"
                        strokeWidth="1"
                    />
                    <text
                        x={padL - 6}
                        y={6 + H * t + 3}
                        textAnchor="end"
                        fill="var(--fg-3)"
                        fontSize="10"
                        fontFamily="var(--font-mono)"
                    >
                        {Math.round(max - (max - min) * t)}
                    </text>
                </g>
            ))}
            {labels?.map((l, i) => (
                <text
                    key={i}
                    x={padL + (i / (labels.length - 1)) * W}
                    y={height - 4}
                    textAnchor="middle"
                    fill="var(--fg-3)"
                    fontSize="10"
                    fontFamily="var(--font-mono)"
                >
                    {l}
                </text>
            ))}
            {series.map((s, si) => {
                const pts = xy(s.data);
                const line = pts
                    .map((p) => `${p[0].toFixed(1)},${p[1].toFixed(1)}`)
                    .join(' ');

                return (
                    <polyline
                        key={si}
                        fill="none"
                        stroke={s.color}
                        strokeWidth="2"
                        points={line}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    />
                );
            })}
        </svg>
    );
}

export interface DonutSegment {
    value: number;
    color: string;
    label: string;
}

export interface DonutProps {
    segments: DonutSegment[];
    size?: number;
    thickness?: number;
    centerLabel?: string | number;
    centerSub?: string;
}

export function Donut({
    segments,
    size = 160,
    thickness = 22,
    centerLabel,
    centerSub,
}: DonutProps) {
    const total = segments.reduce((s, x) => s + x.value, 0) || 1;
    const r = (size - thickness) / 2;
    const cx = size / 2;
    const cy = size / 2;
    const circ = 2 * Math.PI * r;

    // Pre-compute cumulative offsets so we avoid mutating a variable inside .map()
    const offsets = segments.reduce<number[]>((acc, s) => {
        const prev = acc[acc.length - 1] ?? 0;

        return [...acc, prev + (s.value / total) * circ];
    }, []);

    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            <circle
                cx={cx}
                cy={cy}
                r={r}
                fill="none"
                stroke="var(--surface-3)"
                strokeWidth={thickness}
            />
            {segments.map((s, i) => {
                const dash = (s.value / total) * circ;
                const dashOffset = -(offsets[i - 1] ?? 0);

                return (
                    <circle
                        key={i}
                        cx={cx}
                        cy={cy}
                        r={r}
                        fill="none"
                        stroke={s.color}
                        strokeWidth={thickness}
                        strokeDasharray={`${dash} ${circ - dash}`}
                        strokeDashoffset={dashOffset}
                        transform={`rotate(-90 ${cx} ${cy})`}
                        strokeLinecap="butt"
                    >
                        <title>
                            {s.label}: {s.value}
                        </title>
                    </circle>
                );
            })}
            {centerLabel != null && (
                <text
                    x={cx}
                    y={cy - 2}
                    textAnchor="middle"
                    fill="var(--fg-1)"
                    fontSize="22"
                    fontFamily="var(--font-mono)"
                    fontWeight="600"
                >
                    {centerLabel}
                </text>
            )}
            {centerSub && (
                <text
                    x={cx}
                    y={cy + 16}
                    textAnchor="middle"
                    fill="var(--fg-3)"
                    fontSize="11"
                >
                    {centerSub}
                </text>
            )}
        </svg>
    );
}

export interface GaugeProps {
    value: number;
    max?: number;
    size?: number;
    color?: string;
    label?: string;
}

export function Gauge({
    value,
    max = 100,
    size = 120,
    color = 'var(--health-ok)',
    label,
}: GaugeProps) {
    const r = size / 2 - 10;
    const cx = size / 2;
    const cy = size / 2;
    const circ = Math.PI * r;
    const frac = Math.min(1, value / max);

    return (
        <svg
            width={size}
            height={size / 2 + 16}
            viewBox={`0 0 ${size} ${size / 2 + 16}`}
        >
            <path
                d={`M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`}
                fill="none"
                stroke="var(--surface-3)"
                strokeWidth="10"
                strokeLinecap="round"
            />
            <path
                d={`M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`}
                fill="none"
                stroke={color}
                strokeWidth="10"
                strokeLinecap="round"
                strokeDasharray={`${frac * circ} ${circ}`}
            />
            <text
                x={cx}
                y={cy - 2}
                textAnchor="middle"
                fill="var(--fg-1)"
                fontSize="22"
                fontFamily="var(--font-mono)"
                fontWeight="600"
            >
                {value}
            </text>
            {label && (
                <text
                    x={cx}
                    y={cy + 14}
                    textAnchor="middle"
                    fill="var(--fg-3)"
                    fontSize="11"
                >
                    {label}
                </text>
            )}
        </svg>
    );
}
