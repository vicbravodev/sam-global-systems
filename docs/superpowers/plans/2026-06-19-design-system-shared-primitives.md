# Track A — Shared UI Primitives Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the six reusable presentational primitives that the design-system benchmark identified as missing across 8+ screens, so the screen-level parity work (Track C) is assembly, not reinvention.

**Architecture:** Pure presentational React 19 + TypeScript components under `resources/js/components/sam/` (and `ui/` for the generic `Switch`), styled exclusively with the existing OKLCH Tailwind v4 tokens. No new runtime dependencies. Charts are dependency-free inline SVG ported from the design source `src/Charts.jsx`. Each primitive is self-contained and verified by `tsc` + eslint + `vite build`; a removable dev gallery page provides visual verification because the repo has no component test runner.

**Tech Stack:** React 19, TypeScript, Inertia v3, Tailwind v4 (token utilities), lucide-react, `cn()` from `@/lib/utils`. No Vitest/Jest/Testing-Library in repo (do not add without explicit approval).

---

## Why this plan exists (benchmark context)

The 2026-06-19 benchmark vs. the "SAM V1" design system found the token foundation and the spine screens (Dashboard, Incident Detail, Inbox) at or above parity, but the same ~6 missing patterns recur across every low-fidelity screen. Building them once unlocks roughly half the P1 gaps. Mapping:

| Primitive (this plan) | Unblocks P1/P2 gaps on |
|---|---|
| `KpiStrip` / `Kpi` / `Delta` | Events, Assets, Drivers, Rules, Analytics, Integrations, Notifications, Team |
| Charts (`SparkArea`, `Bars`, `LineChart`, `Donut`, `Gauge`) | Analytics, Dashboard, Assets, Drivers, Rules |
| `TabBar` (tabs + counts) | Events, Assets, Drivers, Notifications, Audit |
| `DetailResizer` | Inbox, Events, Assets, Drivers, Integrations |
| `FilterChip` + `AddFilterButton` | Inbox, Events, Assets, Integrations |
| `Switch` + `Field` | Settings, Automations |

This Track produces the primitives only. Wiring them into screens is Track C (separate plan), but each primitive below documents the **integration contract** Track C will rely on, so the APIs are correct the first time.

## Constraints discovered (do not violate)

1. **No JS test runner.** `package.json` has no vitest/jest/testing-library; npm scripts are `build`, `types:check` (`tsc --noEmit`), `lint:check` (`eslint .`), `format:check` (`prettier --check resources/`). TDD here means: a type-level/lint/build gate per task, plus the dev gallery (Task 7) for visual confirmation. **Do not add a test runner** (CLAUDE.md §6 bans `package.json` changes without approval).
2. **No new dependencies.** Build `Switch` and `TabBar` by hand (the design source uses plain CSS, not Radix, for these). `@radix-ui/react-toggle-group` already exists if a segmented variant is later needed.
3. **Tokens, not structural classes.** `resources/css/app.css` defines only the typography `.sam-*` classes; layout in this codebase is Tailwind utilities over tokens. Port the design source's *visuals* (via token utilities/inline token styles), **not** its `.sam-kpi`/`.sam-tabs`/`.sam-chart-axis` class names.
4. **Reuse, don't duplicate.** `DataTable` already exposes `DataTableDensity` and row selection (`resources/js/components/sam/data-table/data-table.tsx:16,36,103`). Do not build a parallel table. `ui/select`, `ui/icon`, `ui/button`, `ui/tooltip` already exist.
5. **Spanish UI, dark-default, reduced-motion.** All visible strings in neutral Spanish; gate animation behind `motion-safe:`; consume `--ease-out` / `--motion-*` tokens.
6. **Bootstrap the worktree first** (CLAUDE.md §3.x): `vendor` symlink, `.env`, `php artisan wayfinder:generate --with-form` before any gate runs.

## Testing strategy (adapted to this repo)

- **Per-task gate (the "test"):** `npm run types:check && npm run lint:check && npm run build`. A task is "red" if any fail, "green" when all pass. Type errors are the primary correctness signal for typed presentational components.
- **Visual verification:** Task 7 adds a temporary `/_dev/primitives` page rendering every primitive with representative props; verify in the browser preview (CLAUDE.md preview recipe with `SESSION_DRIVER=file ... php artisan serve`), then remove the page before the final commit.
- **Screen-level correctness** (Inertia `assertInertia` PHPUnit tests, e.g. `tests/Feature/Domains/Incidents/IncidentInboxTest.php`) belongs to Track C, when these primitives are mounted on real pages.
- **Format:** run `vendor/bin/pint --dirty --format agent` only if PHP changes (none here); run `npm run format:check` and fix with `prettier --write` on touched files.

## File structure

```
resources/js/components/sam/
  charts.tsx            # SparkArea, Bars, LineChart, Donut, Gauge  (port of src/Charts.jsx → TSX, token-driven)
  kpi-strip.tsx         # KpiStrip, Kpi, Delta
  tab-bar.tsx           # TabBar (underline tabs with optional count badges)
  detail-resizer.tsx    # DetailResizer (drag-resize a .has-detail two-pane grid, localStorage width)
  filter-chip.tsx       # FilterChip (removable), AddFilterButton
  field.tsx             # Field (240px label/control row), FormCard
  index.ts              # re-export the above (barrel already exists; append)
resources/js/components/ui/
  switch.tsx            # Switch (role=switch, token-styled, dependency-free)
resources/js/pages/_dev/
  primitives.tsx        # TEMP visual gallery — created in Task 7, removed before final commit
```

Each file has one responsibility and is independently reviewable. `index.ts` is the single integration surface Track C imports from (`@/components/sam`).

---

### Task 0: Bootstrap worktree + baseline green

**Files:** none (environment only)

- [ ] **Step 1: Bootstrap the worktree** (idempotent)

```bash
MAIN="$(git worktree list --porcelain | grep -m1 '^worktree ' | cut -d' ' -f2)"
[ -e vendor ] || ln -s "$MAIN/vendor" vendor
[ -f .env ] || cp "$MAIN/.env" .env
php artisan wayfinder:generate --with-form
```

- [ ] **Step 2: Confirm a green baseline before adding anything**

Run: `npm run types:check && npm run lint:check && npm run build`
Expected: all three exit 0. If `tsc` reports pre-existing errors unrelated to this work, record them so new errors are distinguishable.

- [ ] **Step 3: Confirm `cn` helper path**

Run: `grep -n "export function cn" resources/js/lib/utils.ts`
Expected: a match. Every primitive imports `cn` from `@/lib/utils`.

---

### Task 1: Charts primitives (`charts.tsx`)

Port the five used charts from `src/Charts.jsx` to typed, token-driven TSX. Omit `SamHeatmap` (YAGNI — no prioritized screen needs it; add later if Analytics asks for an hour-of-day heatmap). Replace the design source's missing CSS classes (`sam-map-grid-line`, `sam-bar-track`, `sam-chart-axis`, `sam-donut-center*`) with inline token styles. Use `useId()` for the gradient id instead of the source's `Math.round` hack.

**Files:**
- Create: `resources/js/components/sam/charts.tsx`
- Modify: `resources/js/components/sam/index.ts` (append exports)

- [ ] **Step 1: Write `charts.tsx`**

```tsx
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

export function SparkArea({ data, width = 300, height = 60, color = 'var(--accent)', fill = true }: SparkAreaProps) {
    const uid = useId();
    if (!data || data.length < 2) return null;
    const max = Math.max(...data);
    const min = Math.min(...data);
    const range = max - min || 1;
    const pts = data.map((v, i) => {
        const x = (i / (data.length - 1)) * (width - 2) + 1;
        const y = height - 3 - ((v - min) / range) * (height - 8);
        return [x, y] as const;
    });
    const line = pts.map((p) => `${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ');
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
                            <stop offset="0%" stopColor={color} stopOpacity="0.28" />
                            <stop offset="100%" stopColor={color} stopOpacity="0" />
                        </linearGradient>
                    </defs>
                    <polygon fill={`url(#${uid})`} points={`1,${height - 1} ${line} ${width - 1},${height - 1}`} />
                </>
            )}
            <polyline fill="none" stroke={color} strokeWidth="1.6" points={line} strokeLinecap="round" strokeLinejoin="round" />
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

export function Bars({ data, labels, width = 460, height = 180, color = 'var(--chart-1)', valueFmt = (v) => v }: BarsProps) {
    const max = Math.max(...data, 1);
    const pad = 22;
    const bw = (width - pad) / data.length;
    return (
        <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} className="block">
            {[0, 0.5, 1].map((t) => (
                <line key={t} x1={pad} x2={width} y1={(height - 20) * t + 4} y2={(height - 20) * t + 4} stroke="var(--border)" strokeWidth="1" />
            ))}
            {data.map((v, i) => {
                const h = (v / max) * (height - 30);
                const x = pad + i * bw + bw * 0.18;
                const w = bw * 0.64;
                const y = height - 20 - h;
                return (
                    <g key={i}>
                        <rect x={x} y={4} width={w} height={height - 24} rx="2" fill="var(--surface-3)" opacity="0.5" />
                        <rect x={x} y={y} width={w} height={h} rx="2" fill={color}>
                            <title>{String(valueFmt(v))}</title>
                        </rect>
                        {labels && (
                            <text x={x + w / 2} y={height - 6} textAnchor="middle" fill="var(--fg-3)" fontSize="10" fontFamily="var(--font-mono)">
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

export function LineChart({ series, labels, width = 520, height = 200 }: LineChartProps) {
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
        <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} className="block">
            {[0, 0.25, 0.5, 0.75, 1].map((t) => (
                <g key={t}>
                    <line x1={padL} x2={width} y1={6 + H * t} y2={6 + H * t} stroke="var(--border)" strokeWidth="1" />
                    <text x={padL - 6} y={6 + H * t + 3} textAnchor="end" fill="var(--fg-3)" fontSize="10" fontFamily="var(--font-mono)">
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
                const line = pts.map((p) => `${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(' ');
                return <polyline key={si} fill="none" stroke={s.color} strokeWidth="2" points={line} strokeLinecap="round" strokeLinejoin="round" />;
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

export function Donut({ segments, size = 160, thickness = 22, centerLabel, centerSub }: DonutProps) {
    const total = segments.reduce((s, x) => s + x.value, 0) || 1;
    const r = (size - thickness) / 2;
    const cx = size / 2;
    const cy = size / 2;
    const circ = 2 * Math.PI * r;
    let offset = 0;
    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            <circle cx={cx} cy={cy} r={r} fill="none" stroke="var(--surface-3)" strokeWidth={thickness} />
            {segments.map((s, i) => {
                const frac = s.value / total;
                const dash = frac * circ;
                const el = (
                    <circle
                        key={i}
                        cx={cx}
                        cy={cy}
                        r={r}
                        fill="none"
                        stroke={s.color}
                        strokeWidth={thickness}
                        strokeDasharray={`${dash} ${circ - dash}`}
                        strokeDashoffset={-offset}
                        transform={`rotate(-90 ${cx} ${cy})`}
                        strokeLinecap="butt"
                    >
                        <title>
                            {s.label}: {s.value}
                        </title>
                    </circle>
                );
                offset += dash;
                return el;
            })}
            {centerLabel != null && (
                <text x={cx} y={cy - 2} textAnchor="middle" fill="var(--fg-1)" fontSize="22" fontFamily="var(--font-mono)" fontWeight="600">
                    {centerLabel}
                </text>
            )}
            {centerSub && (
                <text x={cx} y={cy + 16} textAnchor="middle" fill="var(--fg-3)" fontSize="11">
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

export function Gauge({ value, max = 100, size = 120, color = 'var(--health-ok)', label }: GaugeProps) {
    const r = size / 2 - 10;
    const cx = size / 2;
    const cy = size / 2;
    const circ = Math.PI * r;
    const frac = Math.min(1, value / max);
    return (
        <svg width={size} height={size / 2 + 16} viewBox={`0 0 ${size} ${size / 2 + 16}`}>
            <path d={`M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`} fill="none" stroke="var(--surface-3)" strokeWidth="10" strokeLinecap="round" />
            <path
                d={`M ${cx - r} ${cy} A ${r} ${r} 0 0 1 ${cx + r} ${cy}`}
                fill="none"
                stroke={color}
                strokeWidth="10"
                strokeLinecap="round"
                strokeDasharray={`${frac * circ} ${circ}`}
            />
            <text x={cx} y={cy - 2} textAnchor="middle" fill="var(--fg-1)" fontSize="22" fontFamily="var(--font-mono)" fontWeight="600">
                {value}
            </text>
            {label && (
                <text x={cx} y={cy + 14} textAnchor="middle" fill="var(--fg-3)" fontSize="11">
                    {label}
                </text>
            )}
        </svg>
    );
}
```

- [ ] **Step 2: Append to the barrel** `resources/js/components/sam/index.ts`

```ts
export * from './charts';
```

- [ ] **Step 3: Gate**

Run: `npm run types:check && npm run lint:check`
Expected: PASS (no type errors, no lint errors). If eslint flags `valueFmt` default or `aria-hidden`, fix per its message.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/sam/charts.tsx resources/js/components/sam/index.ts
git commit -m "feat(ui): add token-driven SVG chart primitives (SparkArea/Bars/LineChart/Donut/Gauge)"
```

**Integration contract (Track C):** Analytics consumes `Bars` (30-day volume), `Donut` (severity split + center total), `LineChart` (SLA trend), `Delta` on stat cards. Dashboard/Assets/Rules reuse `SparkArea` for KPI sparklines. Drivers uses `Gauge` for the risk gauge. Colors are passed as `var(--chart-N)` / `var(--severity-*)` strings.

---

### Task 2: KPI strip (`kpi-strip.tsx`)

`Delta` encodes "lower is better" metrics via `invert` (e.g. MTTA down = good = green). `Kpi` is one card: caps label, big mono value + optional unit, optional `Delta`, optional `sparkline` slot. `KpiStrip` is the responsive hairline-gap grid wrapper used at the top of list screens.

**Files:**
- Create: `resources/js/components/sam/kpi-strip.tsx`
- Modify: `resources/js/components/sam/index.ts`

- [ ] **Step 1: Write `kpi-strip.tsx`**

```tsx
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface DeltaProps {
    /** Signed change, e.g. 12 or -4. */
    value: number;
    /** Suffix rendered after the number, e.g. '%'. */
    unit?: string;
    /** When true, a negative value is "good" (green) — for metrics like MTTA. */
    invert?: boolean;
    className?: string;
}

/** Signed, color-coded change indicator with a trend arrow. */
export function Delta({ value, unit = '%', invert = false, className }: DeltaProps) {
    if (value === 0) {
        return <span className={cn('inline-flex items-center gap-0.5 font-mono text-2xs text-fg-3 tabular-nums', className)}>0{unit}</span>;
    }
    const up = value > 0;
    const good = invert ? !up : up;
    const Arrow = up ? ArrowUpRight : ArrowDownRight;
    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 font-mono text-2xs tabular-nums',
                good ? 'text-health-ok' : 'text-severity-high',
                className,
            )}
        >
            <Arrow className="size-3" aria-hidden="true" />
            {up ? '+' : ''}
            {value}
            {unit}
        </span>
    );
}

export interface KpiProps {
    label: string;
    value: ReactNode;
    unit?: string;
    delta?: DeltaProps;
    /** Optional visual (e.g. <SparkArea/> or a <Bar/>) rendered under the value. */
    sparkline?: ReactNode;
    className?: string;
}

/** A single KPI card. Big tabular-mono value, caps label, optional delta + sparkline. */
export function Kpi({ label, value, unit, delta, sparkline, className }: KpiProps) {
    return (
        <div className={cn('relative flex min-h-[100px] flex-col gap-1 overflow-hidden bg-surface-1 p-4', className)}>
            <span className="text-2xs font-semibold uppercase tracking-caps text-fg-3">{label}</span>
            <div className="flex items-baseline gap-1">
                <span className="font-mono text-2xl font-semibold tabular-nums tracking-tight text-fg-1">{value}</span>
                {unit && <span className="text-xs text-fg-3">{unit}</span>}
            </div>
            {delta && <Delta {...delta} />}
            {sparkline && <div className="mt-auto pt-2">{sparkline}</div>}
        </div>
    );
}

export interface KpiStripProps {
    children: ReactNode;
    /** Column count at the lg breakpoint. Default 4. */
    cols?: 3 | 4 | 5 | 6;
    className?: string;
}

const COLS: Record<NonNullable<KpiStripProps['cols']>, string> = {
    3: 'lg:grid-cols-3',
    4: 'lg:grid-cols-4',
    5: 'lg:grid-cols-5',
    6: 'lg:grid-cols-6',
};

/**
 * Hairline-separated KPI strip. Uses a 1px gap over a border-tinted surface so
 * the cards read as one franja (matching the dashboard cockpit), collapsing to
 * 2 columns on mobile.
 */
export function KpiStrip({ children, cols = 4, className }: KpiStripProps) {
    return (
        <div className={cn('grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border', COLS[cols], className)}>
            {children}
        </div>
    );
}
```

- [ ] **Step 2: Append to barrel**

```ts
export * from './kpi-strip';
```

- [ ] **Step 3: Gate**

Run: `npm run types:check && npm run lint:check`
Expected: PASS. Confirm `text-health-ok`, `text-severity-high`, `tracking-caps`, `text-2xs` resolve (they are used elsewhere in the codebase, e.g. severity badges / DESIGN.md). If any utility is unknown to Tailwind, check the `@theme` block in `resources/css/app.css` and use the registered name.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/sam/kpi-strip.tsx resources/js/components/sam/index.ts
git commit -m "feat(ui): add KpiStrip/Kpi/Delta primitives for list-screen metric strips"
```

**Integration contract (Track C):** `<KpiStrip cols={5}>` for Assets/Drivers/Integrations/Rules, `cols={4}` for Notifications/Events. The `sparkline` slot takes `<SparkArea data={...} color="var(--severity-critical)" />`. `Delta` `invert` is set on MTTA/false-positive-rate style metrics.

---

### Task 3: Tab bar with counts (`tab-bar.tsx`)

The underline tab pattern used across the proposal's list screens (inbox/events/assets/notifications/audit), with optional count badges. Built dependency-free with proper `tablist`/`tab` semantics and arrow-key navigation.

**Files:**
- Create: `resources/js/components/sam/tab-bar.tsx`
- Modify: `resources/js/components/sam/index.ts`

- [ ] **Step 1: Write `tab-bar.tsx`**

```tsx
import { cn } from '@/lib/utils';

export interface TabItem {
    key: string;
    label: string;
    /** Optional count badge; omit for tabs without a count. */
    count?: number;
}

export interface TabBarProps {
    items: TabItem[];
    value: string;
    onChange: (key: string) => void;
    className?: string;
    'aria-label'?: string;
}

/**
 * Underline tab strip with optional count badges. Matches the design system's
 * .sam-tab pattern (active = primary underline). Keyboard: ArrowLeft/Right move
 * focus and selection between tabs.
 */
export function TabBar({ items, value, onChange, className, 'aria-label': ariaLabel }: TabBarProps) {
    const move = (dir: 1 | -1) => {
        const idx = items.findIndex((t) => t.key === value);
        const next = items[(idx + dir + items.length) % items.length];
        if (next) onChange(next.key);
    };
    return (
        <div role="tablist" aria-label={ariaLabel} className={cn('flex items-center gap-0.5 border-b border-border', className)}>
            {items.map((t) => {
                const active = t.key === value;
                return (
                    <button
                        key={t.key}
                        role="tab"
                        type="button"
                        aria-selected={active}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(t.key)}
                        onKeyDown={(e) => {
                            if (e.key === 'ArrowRight') {
                                e.preventDefault();
                                move(1);
                            } else if (e.key === 'ArrowLeft') {
                                e.preventDefault();
                                move(-1);
                            }
                        }}
                        className={cn(
                            '-mb-px flex items-center gap-1.5 whitespace-nowrap border-b-2 px-2.5 py-2.5 text-sm font-medium transition-colors motion-safe:duration-(--motion-fast) ease-(--ease-out)',
                            active ? 'border-primary text-fg-1' : 'border-transparent text-fg-3 hover:text-fg-1',
                        )}
                    >
                        {t.label}
                        {t.count != null && (
                            <span
                                className={cn(
                                    'rounded-full px-1.5 font-mono text-2xs tabular-nums',
                                    active ? 'bg-primary text-primary-foreground' : 'bg-surface-3 text-fg-2',
                                )}
                            >
                                {t.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
```

- [ ] **Step 2: Append to barrel**

```ts
export * from './tab-bar';
```

- [ ] **Step 3: Gate**

Run: `npm run types:check && npm run lint:check`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/sam/tab-bar.tsx resources/js/components/sam/index.ts
git commit -m "feat(ui): add TabBar (underline tabs with count badges, keyboard nav)"
```

**Integration contract (Track C):** screens pass `items` derived from server-side counts and map `value` to the existing filter/reload pipeline (e.g. the inbox status filter). Replaces ad-hoc inline tab markup.

---

### Task 4: Detail resizer (`detail-resizer.tsx`)

Port `DetailResizer` from `src/Primitives.jsx` to TSX. Drag the gutter to resize a two-pane detail layout, persist width to `localStorage`, double-click to reset, and step out (clear the inline template) below 1000px so mobile stacking still works. Drives the nearest `.has-detail` ancestor's `grid-template-columns`.

**Files:**
- Create: `resources/js/components/sam/detail-resizer.tsx`
- Modify: `resources/js/components/sam/index.ts`

- [ ] **Step 1: Write `detail-resizer.tsx`**

```tsx
import { useEffect, useRef } from 'react';

export interface DetailResizerProps {
    /** Min detail-pane width in px. */
    min?: number;
    /** Max detail-pane width in px. */
    max?: number;
    /** Default width when unset / after reset. */
    defaultWidth?: number;
    /** localStorage key for persisting the chosen width. */
    storageKey?: string;
}

/**
 * Drag handle for a two-pane detail layout. Mount it as the FIRST child of the
 * detail panel inside a container that has the `has-detail` class and a
 * `grid-template-columns: minmax(0,1fr) <detail>` track. Drag to resize, persist
 * to localStorage, double-click to reset. Below 1000px it clears the inline
 * template so the layout can stack via CSS.
 */
export function DetailResizer({ min = 380, max = 980, defaultWidth = 600, storageKey = 'sam-detail-w' }: DetailResizerProps) {
    const ref = useRef<HTMLDivElement>(null);

    const apply = (root: HTMLElement | null, w: number) => {
        if (!root) return;
        if (window.innerWidth < 1000) {
            root.style.gridTemplateColumns = '';
            return;
        }
        root.style.gridTemplateColumns = `minmax(0,1fr) ${w}px`;
    };

    const readWidth = () => {
        const stored = parseInt(localStorage.getItem(storageKey) ?? '', 10);
        const w = Number.isFinite(stored) ? stored : defaultWidth;
        return Math.max(min, Math.min(max, w));
    };

    useEffect(() => {
        const root = ref.current?.closest<HTMLElement>('.has-detail') ?? null;
        if (!root) return;
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
        if (!root || !aside) return;
        const startX = e.clientX;
        const startW = aside.getBoundingClientRect().width;
        document.body.style.cursor = 'col-resize';
        document.body.style.userSelect = 'none';
        ref.current?.classList.add('dragging');

        const move = (ev: PointerEvent) => {
            const w = Math.max(min, Math.min(max, startW + (startX - ev.clientX)));
            root.style.gridTemplateColumns = `minmax(0,1fr) ${w}px`;
        };
        const up = () => {
            const w = Math.round(aside.getBoundingClientRect().width);
            localStorage.setItem(storageKey, String(w));
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', up);
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
            ref.current?.classList.remove('dragging');
        };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', up);
    };

    const reset = () => {
        localStorage.setItem(storageKey, String(defaultWidth));
        apply(ref.current?.closest<HTMLElement>('.has-detail') ?? null, defaultWidth);
    };

    return (
        <div
            ref={ref}
            onPointerDown={onPointerDown}
            onDoubleClick={reset}
            role="separator"
            aria-orientation="vertical"
            title="Arrastrar para redimensionar · doble clic para restablecer"
            className="absolute left-0 top-0 z-40 h-full w-2.5 -translate-x-1/2 cursor-col-resize touch-none before:absolute before:left-1/2 before:top-0 before:h-full before:w-px before:-translate-x-1/2 before:bg-border before:transition-[background,width] before:duration-(--motion-fast) hover:before:w-[3px] hover:before:bg-primary [&.dragging]:before:w-[3px] [&.dragging]:before:bg-primary"
        />
    );
}
```

- [ ] **Step 2: Append to barrel**

```ts
export * from './detail-resizer';
```

- [ ] **Step 3: Gate**

Run: `npm run types:check && npm run lint:check`
Expected: PASS. The `[&.dragging]:` arbitrary variant and `before:` utilities are valid Tailwind v4; if eslint/tsc complains about the `react-hooks/exhaustive-deps` disable, keep it (the effect intentionally runs once).

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/sam/detail-resizer.tsx resources/js/components/sam/index.ts
git commit -m "feat(ui): add DetailResizer (drag-resize two-pane detail, persisted width)"
```

**Integration contract (Track C):** the consuming two-pane container must (1) add the `has-detail` class and (2) use `style={{ gridTemplateColumns: 'minmax(0,1fr) 600px' }}` (or the existing responsive track) and be `position: relative`. Mount `<DetailResizer />` as the first child of the detail `<aside>`. For the incident inbox this replaces the fixed `md:grid-cols-[1fr_minmax(520px,700px)]` track on `resources/js/pages/incidents/index.tsx:1052`.

---

### Task 5: Filter chips (`filter-chip.tsx`)

Removable applied-filter pill + dashed "add filter" affordance, matching the proposal's `.sam-filter-chip` / `.sam-filter-add`. Saved-view selection reuses the existing `ui/select` (no new component).

**Files:**
- Create: `resources/js/components/sam/filter-chip.tsx`
- Modify: `resources/js/components/sam/index.ts`

- [ ] **Step 1: Write `filter-chip.tsx`**

```tsx
import { Plus, X } from 'lucide-react';

import { cn } from '@/lib/utils';

export interface FilterChipProps {
    /** Filter dimension, e.g. 'Severidad'. */
    label: string;
    /** Selected value, e.g. 'Crítica'. Omit for a label-only chip. */
    value?: string;
    onRemove: () => void;
    className?: string;
}

/** A removable applied-filter pill (label: value + ✕). */
export function FilterChip({ label, value, onRemove, className }: FilterChipProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-1 px-2 py-1 text-2xs font-medium text-fg-1',
                className,
            )}
        >
            <span className="text-fg-3">{label}</span>
            {value && <span>{value}</span>}
            <button
                type="button"
                onClick={onRemove}
                aria-label={`Quitar filtro ${label}`}
                className="grid place-items-center text-fg-3 transition-colors hover:text-fg-1"
            >
                <X className="size-3" />
            </button>
        </span>
    );
}

export interface AddFilterButtonProps {
    onClick: () => void;
    label?: string;
    className?: string;
}

/** Dashed-outline "add filter" trigger. */
export function AddFilterButton({ onClick, label = 'Agregar filtro', className }: AddFilterButtonProps) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border border-dashed border-border-strong px-2 py-1 text-2xs font-medium text-fg-3 transition-colors hover:text-fg-1',
                className,
            )}
        >
            <Plus className="size-3" />
            {label}
        </button>
    );
}
```

- [ ] **Step 2: Append to barrel**

```ts
export * from './filter-chip';
```

- [ ] **Step 3: Gate**

Run: `npm run types:check && npm run lint:check`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/sam/filter-chip.tsx resources/js/components/sam/index.ts
git commit -m "feat(ui): add FilterChip + AddFilterButton (removable filter vocabulary)"
```

**Integration contract (Track C):** screens render one `<FilterChip>` per applied filter with `onRemove` clearing that single dimension, plus an `<AddFilterButton>` opening the existing dropdown(s). Saved views use `<Select>` from `@/components/ui/select`.

---

### Task 6: Switch + Field (`ui/switch.tsx`, `sam/field.tsx`)

A dependency-free toggle (`role="switch"`, keyboard, token-styled like `.sam-switch`) and the `.sam-field` two-column label/control row used by settings/automations forms.

**Files:**
- Create: `resources/js/components/ui/switch.tsx`
- Create: `resources/js/components/sam/field.tsx`
- Modify: `resources/js/components/sam/index.ts`

- [ ] **Step 1: Write `ui/switch.tsx`**

```tsx
import { cn } from '@/lib/utils';

export interface SwitchProps {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    id?: string;
    'aria-label'?: string;
    'aria-labelledby'?: string;
    className?: string;
}

/**
 * Accessible toggle switch, token-styled to match the design system .sam-switch
 * (health-ok tint when on). Dependency-free — no Radix package required.
 */
export function Switch({ checked, onCheckedChange, disabled, id, className, ...aria }: SwitchProps) {
    return (
        <button
            id={id}
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={aria['aria-label']}
            aria-labelledby={aria['aria-labelledby']}
            disabled={disabled}
            onClick={() => !disabled && onCheckedChange(!checked)}
            className={cn(
                'relative h-[22px] w-[38px] shrink-0 rounded-full border transition-colors motion-safe:duration-(--motion-fast) ease-(--ease-out) focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'border-health-ok/50 bg-health-ok/35' : 'border-border bg-surface-3',
                className,
            )}
        >
            <span
                className={cn(
                    'absolute top-[2px] left-[2px] size-4 rounded-full transition-transform motion-safe:duration-(--motion-fast) ease-(--ease-out)',
                    checked ? 'translate-x-4 bg-health-ok' : 'bg-fg-3',
                )}
            />
        </button>
    );
}
```

- [ ] **Step 2: Write `sam/field.tsx`**

```tsx
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export interface FieldProps {
    label: string;
    /** Helper text under the label. */
    help?: string;
    /** Associates the label with a control id for a11y. */
    htmlFor?: string;
    children: ReactNode;
    className?: string;
}

/**
 * Two-column settings field: a 240px label/help column and a control column,
 * stacking below 760px. Matches the design system .sam-field layout.
 */
export function Field({ label, help, htmlFor, children, className }: FieldProps) {
    return (
        <div className={cn('grid grid-cols-1 items-start gap-2 sm:grid-cols-[240px_1fr] sm:gap-4', className)}>
            <label htmlFor={htmlFor} className="flex flex-col gap-0.5">
                <span className="text-sm font-semibold text-fg-1">{label}</span>
                {help && <span className="text-2xs leading-relaxed text-fg-3">{help}</span>}
            </label>
            <div className="flex min-w-0 flex-col gap-2">{children}</div>
        </div>
    );
}

export interface FormCardProps {
    children: ReactNode;
    className?: string;
}

/** Bordered surface that groups Fields, max-width for readable forms. */
export function FormCard({ children, className }: FormCardProps) {
    return <div className={cn('flex max-w-3xl flex-col gap-4 rounded-lg border border-border bg-surface-1 p-5', className)}>{children}</div>;
}
```

- [ ] **Step 3: Append to barrel**

```ts
export * from './field';
```

(Note: `Switch` lives in `ui/` per shadcn convention; import it as `@/components/ui/switch`. It is intentionally not re-exported from the `sam` barrel.)

- [ ] **Step 4: Gate**

Run: `npm run types:check && npm run lint:check`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/ui/switch.tsx resources/js/components/sam/field.tsx resources/js/components/sam/index.ts
git commit -m "feat(ui): add Switch (dependency-free) and Field/FormCard for guided settings forms"
```

**Integration contract (Track C):** Settings replaces checkboxes with `<Switch>` and stacked labels with `<Field>`/`<FormCard>`. Automations uses `<Switch>` for the pause/activate toggle.

---

### Task 7: Temporary visual gallery + full build gate (then remove)

Because there is no component test runner, render every primitive once with representative props for a manual preview, run the full build gate, then delete the page so it never ships.

**Files:**
- Create (temporary): `resources/js/pages/_dev/primitives.tsx`

- [ ] **Step 1: Write the gallery page**

```tsx
import { Switch } from '@/components/ui/switch';
import {
    AddFilterButton,
    Bars,
    Delta,
    Donut,
    Field,
    FilterChip,
    FormCard,
    Gauge,
    Kpi,
    KpiStrip,
    LineChart,
    SparkArea,
    TabBar,
} from '@/components/sam';
import { useState } from 'react';

export default function PrimitivesGallery() {
    const [tab, setTab] = useState('all');
    const [on, setOn] = useState(true);
    return (
        <div className="flex flex-col gap-8 p-8">
            <KpiStrip cols={4}>
                <Kpi label="Incidentes abiertos" value={42} delta={{ value: 12 }} sparkline={<SparkArea data={[3, 5, 4, 8, 6, 9, 12]} color="var(--severity-critical)" />} />
                <Kpi label="Críticos ahora" value={6} delta={{ value: -2, invert: true }} />
                <Kpi label="SLA cumplido 7d" value="94" unit="%" delta={{ value: 3 }} />
                <Kpi label="Precisión IA" value="88" unit="%" delta={{ value: 1 }} />
            </KpiStrip>

            <TabBar
                aria-label="Demo"
                value={tab}
                onChange={setTab}
                items={[
                    { key: 'all', label: 'Todos', count: 128 },
                    { key: 'crit', label: 'Críticos', count: 6 },
                    { key: 'mine', label: 'Míos', count: 11 },
                ]}
            />

            <div className="flex flex-wrap items-center gap-2">
                <FilterChip label="Severidad" value="Crítica" onRemove={() => {}} />
                <FilterChip label="Estado" value="Sin asignar" onRemove={() => {}} />
                <AddFilterButton onClick={() => {}} />
            </div>

            <div className="flex flex-wrap items-end gap-8">
                <Bars data={[4, 8, 6, 12, 9, 14, 7]} labels={['L', 'M', 'X', 'J', 'V', 'S', 'D']} />
                <Donut
                    size={140}
                    centerLabel={128}
                    centerSub="eventos"
                    segments={[
                        { value: 6, color: 'var(--severity-critical)', label: 'Crítica' },
                        { value: 20, color: 'var(--severity-high)', label: 'Alta' },
                        { value: 40, color: 'var(--severity-medium)', label: 'Media' },
                        { value: 62, color: 'var(--severity-low)', label: 'Baja' },
                    ]}
                />
                <LineChart series={[{ data: [90, 92, 88, 94, 96, 93, 95], color: 'var(--primary)' }]} labels={['L', 'M', 'X', 'J', 'V', 'S', 'D']} />
                <Gauge value={72} label="riesgo" color="var(--severity-high)" />
                <Delta value={-8} invert />
            </div>

            <FormCard>
                <Field label="Monitoreo proactivo" help="Evalúa eventos sin incidente con IA." htmlFor="demo-switch">
                    <Switch id="demo-switch" checked={on} onCheckedChange={setOn} aria-label="Monitoreo proactivo" />
                </Field>
            </FormCard>
        </div>
    );
}
```

- [ ] **Step 2: Run the full build gate**

Run: `npm run types:check && npm run lint:check && npm run format:check && npm run build`
Expected: all PASS. `vite build` confirms every primitive compiles and the barrel resolves. Fix any formatting with `npx prettier --write resources/js/components/sam resources/js/components/ui/switch.tsx`.

- [ ] **Step 3: Visual check in the browser** (per CLAUDE.md preview recipe)

Run:
```bash
SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync DB_CONNECTION=sqlite DB_DATABASE=:memory: \
  php artisan serve --port=8088
```
Then add a throwaway route in `routes/web.php` rendering `Inertia::render('_dev/primitives')` (or load via the dev gallery convention already in the repo if one exists), open `http://localhost:8088/...`, and confirm in **both dark and light**: KPI strip hairline grid, sparkline color, delta arrow direction/color (note `invert` shows green for the −8), tab underline + count badges, removable chips, all five charts, the switch on/off + the 240px Field layout. Capture a screenshot for the PR.

- [ ] **Step 4: Remove the temporary gallery**

```bash
rm resources/js/pages/_dev/primitives.tsx
# remove the throwaway route if one was added
```
Run again: `npm run types:check && npm run build`
Expected: PASS (gallery removed, primitives still compile because they are imported by the barrel’s `export *`).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore(ui): verify shared primitives via build gate (remove temp gallery)"
```

---

## Self-review (run before handing off)

**Spec coverage** — the six benchmark-identified primitives each map to a task: Charts→T1, KpiStrip/Delta→T2, TabBar→T3, DetailResizer→T4, FilterChip/AddFilterButton→T5, Switch/Field→T6. Saved-view selection intentionally reuses `ui/select` (documented in T5), not a new component (YAGNI). `SamHeatmap` intentionally deferred (YAGNI). ✓

**Placeholder scan** — every step contains complete code or an exact command; no TBD/“handle edge cases”/“similar to”. ✓

**Type/name consistency** — barrel re-exports (`charts`, `kpi-strip`, `tab-bar`, `detail-resizer`, `filter-chip`, `field`) all created; `Switch` deliberately imported from `@/components/ui/switch` (not the sam barrel) and the gallery imports it that way; chart prop names (`SparkArea data/color`, `Bars data/labels`, `LineChart series`, `Donut segments/centerLabel`, `Gauge value/label`) match between definition (T1) and usage (T7). `Delta` props (`value/unit/invert`) consistent T2↔T7. ✓

**Assumptions to verify during execution** (fail fast in Task 0): `@/lib/utils` exports `cn`; the token utilities `text-health-ok`, `text-severity-high`, `border-border-strong`, `bg-surface-1/3`, `tracking-caps`, `text-2xs` are registered in the `@theme` block of `resources/css/app.css`. If a name differs, adjust the utility, not the token.

## How this Track fits the larger roadmap

This is the foundation layer. Once merged, the screen-level parity work (tracked in `docs/FRONTEND-ROADMAP.md` and the per-screen gaps in the 2026-06-19 benchmark) becomes assembly:

- **Track B (Notifications P0)** — outbound delivery log: `KpiStrip` + `TabBar` (per-channel) consumed directly.
- **Track C (screen value-layers)** — Analytics (charts), Events (KpiStrip+TabBar+DetailResizer+live stream), Drivers (Gauge), Assets/Integrations/Team (KpiStrip+TabBar; some need backend payload extension first), Settings (Switch+Field), Audit/Map (TabBar/DetailResizer).
- **Track D** — AI Assistant module (independent, multi-PR).
- **Track E** — Shell polish (wire RealtimeStatus to the real socket, persist sidebar collapse, port `motion.css` entrance stagger).

Backend-dependent gaps (Assets row telemetry, Drivers trend, Integrations throughput metrics, Team presence/2FA) need a payload change before their frontend can match the proposal; flag those as paired backend tasks in Track C.
