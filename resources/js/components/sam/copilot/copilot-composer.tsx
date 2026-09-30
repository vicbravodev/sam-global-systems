import {
    ArrowUp,
    Container,
    FileText,
    Fuel,
    Gauge,
    Inbox,
    MapPin,
    Search,
    Siren,
    Square,
    Truck,
    Users,
    Video,
    X,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import type {
    CopilotAssetOption,
    CopilotIntent,
    CopilotSendHints,
    CopilotTemplate,
} from '@/types/copilot';

const TEMPLATE_ICONS: Record<string, React.ElementType> = {
    'file-text': FileText,
    'map-pin': MapPin,
    video: Video,
    gauge: Gauge,
    fuel: Fuel,
    siren: Siren,
    inbox: Inbox,
    truck: Truck,
    users: Users,
};

/**
 * The send button turns into Stop under the pointer: a pointer click that
 * lands this soon after the flip is the second half of a double-click on
 * Enviar, not a stop. Keyboard activation and Esc stay immediate.
 */
const STOP_ARM_MS = 400;

const STATUS_DOT: Record<string, string> = {
    active: 'bg-health-ok',
    alert: 'bg-severity-high',
    critical: 'bg-severity-critical',
    maintenance: 'bg-severity-medium',
};

export function assetDisplay(asset: CopilotAssetOption): string {
    return asset.code ?? asset.name;
}

function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '');
}

export function fillTemplate(
    prompt: string,
    asset: CopilotAssetOption | null,
): string {
    return prompt.replace('{asset}', asset ? assetDisplay(asset) : '…');
}

interface Props {
    assets: CopilotAssetOption[];
    templates: CopilotTemplate[];
    busy: boolean;
    compact?: boolean;
    autoFocus?: boolean;
    asset: CopilotAssetOption | null;
    onAssetChange: (asset: CopilotAssetOption | null) => void;
    onSend: (content: string, hints: CopilotSendHints) => void;
    /** Stops the answer being streamed (the send button turns into it). */
    onStop?: () => void;
    /** Lets parents (suggestion cards) pre-fill the textarea. */
    draft?: {
        text: string;
        intent: CopilotIntent | null;
        nonce: number;
    } | null;
}

export function CopilotComposer({
    assets,
    templates,
    busy,
    compact = false,
    autoFocus = false,
    asset,
    onAssetChange,
    onSend,
    onStop,
    draft,
}: Props) {
    const [text, setText] = useState('');
    const [intent, setIntent] = useState<CopilotIntent | null>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [pendingTemplate, setPendingTemplate] =
        useState<CopilotTemplate | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    /** When the button last became Stop (`performance.now()`). */
    const busySinceRef = useRef(0);
    /** The send/stop button had focus (it disables when the answer lands). */
    const buttonFocusedRef = useRef(false);

    useLayoutEffect(() => {
        if (busy) {
            busySinceRef.current = performance.now();

            return;
        }

        // Stop was clicked (or focused) and now disables: keep the keyboard
        // in the composer instead of dropping focus to <body>.
        if (buttonFocusedRef.current) {
            buttonFocusedRef.current = false;
            textareaRef.current?.focus();
        }
    }, [busy]);

    const stopFromButton = (e: React.MouseEvent) => {
        // `detail` is 0 for keyboard activation (Enter/Space): immediate.
        if (
            e.detail > 0 &&
            performance.now() - busySinceRef.current < STOP_ARM_MS
        ) {
            return;
        }

        onStop?.();
    };

    // A suggestion card pre-fills the composer: adopt each new draft once
    // (render-time sync, see react.dev "adjusting state on prop change").
    const [appliedDraft, setAppliedDraft] = useState<number | null>(null);

    if (draft && draft.nonce !== appliedDraft) {
        setAppliedDraft(draft.nonce);
        setText(draft.text);
        setIntent(draft.intent);
    }

    useEffect(() => {
        if (draft) {
            requestAnimationFrame(() => textareaRef.current?.focus());
        }
    }, [draft]);

    // Grow with the content up to a cap.
    useEffect(() => {
        const el = textareaRef.current;

        if (!el) {
            return;
        }

        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, compact ? 96 : 140)}px`;
    }, [text, compact]);

    // Autocomplete: the word being typed (or an @mention) against unit codes.
    const lastWord = useMemo(() => {
        const match = text.match(/(@?[\p{L}\d-]{2,})$/u);

        return match ? match[1] : null;
    }, [text]);

    const suggestions = useMemo(() => {
        if (!lastWord || assets.length === 0) {
            return [];
        }

        const isMention = lastWord.startsWith('@');
        const needle = normalize(lastWord.replace(/^@/, ''));

        if (!isMention && needle.length < 2) {
            return [];
        }

        return assets
            .filter((a) => {
                const code = normalize(a.code ?? '');
                const name = normalize(a.name);

                return (
                    (code !== '' && code.startsWith(needle)) ||
                    (isMention && name.includes(needle))
                );
            })
            .filter((a) => normalize(a.code ?? '') !== needle || isMention)
            .slice(0, 5);
    }, [lastWord, assets]);

    const applySuggestion = (option: CopilotAssetOption) => {
        if (!lastWord) {
            return;
        }

        setText(
            (current) =>
                current.slice(0, current.length - lastWord.length) +
                assetDisplay(option) +
                ' ',
        );
        onAssetChange(option);
        textareaRef.current?.focus();
    };

    const chooseTemplate = (template: CopilotTemplate) => {
        if (template.needsAsset && !asset) {
            setPendingTemplate(template);
            setPickerOpen(true);

            return;
        }

        setText(fillTemplate(template.prompt, asset));
        setIntent(template.intent);
        requestAnimationFrame(() => textareaRef.current?.focus());
    };

    const chooseAsset = (option: CopilotAssetOption) => {
        onAssetChange(option);
        setPickerOpen(false);

        if (pendingTemplate) {
            setText(fillTemplate(pendingTemplate.prompt, option));
            setIntent(pendingTemplate.intent);
            setPendingTemplate(null);
        }

        requestAnimationFrame(() => textareaRef.current?.focus());
    };

    const submit = () => {
        if (busy || text.trim().length < 2) {
            return;
        }

        busySinceRef.current = performance.now();
        onSend(text, { assetId: asset?.id ?? null, intent });
        setText('');
        setIntent(null);
    };

    const canSend = !busy && text.trim().length >= 2;
    const visibleTemplates = compact ? templates.slice(0, 5) : templates;

    return (
        <div className="flex flex-col gap-2">
            <div
                className={cn(
                    'flex gap-1.5',
                    compact ? 'scrollbar-none overflow-x-auto' : 'flex-wrap',
                )}
            >
                {visibleTemplates.map((template) => {
                    const Icon = TEMPLATE_ICONS[template.icon] ?? FileText;

                    return (
                        <button
                            key={template.intent}
                            type="button"
                            onClick={() => chooseTemplate(template)}
                            className={cn(
                                'inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1 text-2xs font-medium transition-colors',
                                intent === template.intent
                                    ? 'border-ai-accent/60 bg-ai-accent-bg text-ai-accent'
                                    : 'border-border bg-surface-2 text-fg-2 hover:bg-surface-3 hover:text-fg-1',
                            )}
                        >
                            <Icon className="size-3" />
                            {template.label}
                        </button>
                    );
                })}
            </div>

            <div className="relative">
                {suggestions.length > 0 && (
                    <div className="absolute inset-x-0 bottom-full z-20 mb-1.5 overflow-hidden rounded-md border border-border-strong bg-surface-1 shadow-lg">
                        <div className="border-b border-border px-2.5 py-1 text-3xs font-medium tracking-caps text-fg-3 uppercase">
                            ¿Te refieres a…?
                        </div>
                        {suggestions.map((option) => (
                            <button
                                key={option.id}
                                type="button"
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    applySuggestion(option);
                                }}
                                className="flex w-full cursor-pointer items-center gap-2 px-2.5 py-1.5 text-left text-xs hover:bg-surface-2"
                            >
                                <AssetIcon asset={option} />
                                <span className="font-mono font-semibold text-fg-1">
                                    {assetDisplay(option)}
                                </span>
                                <span className="truncate text-fg-3">
                                    {option.name}
                                </span>
                            </button>
                        ))}
                    </div>
                )}

                {pickerOpen && (
                    <AssetPickerPopover
                        assets={assets}
                        title={
                            pendingTemplate
                                ? `${pendingTemplate.label}: elige la unidad`
                                : 'Fijar unidad para la conversación'
                        }
                        onPick={chooseAsset}
                        onClose={() => {
                            setPickerOpen(false);
                            setPendingTemplate(null);
                        }}
                    />
                )}

                <div className="flex flex-col rounded-lg border border-border-strong bg-surface-1 shadow-xs transition-shadow focus-within:border-primary focus-within:ring-3 focus-within:ring-primary/15">
                    <textarea
                        ref={textareaRef}
                        value={text}
                        autoFocus={autoFocus}
                        onChange={(e) => {
                            setText(e.target.value);

                            if (e.target.value.trim() === '') {
                                setIntent(null);
                            }
                        }}
                        onKeyDown={(e) => {
                            if (
                                e.key === 'Enter' &&
                                !e.shiftKey &&
                                !e.nativeEvent.isComposing
                            ) {
                                e.preventDefault();

                                if (
                                    suggestions.length > 0 &&
                                    lastWord?.startsWith('@')
                                ) {
                                    applySuggestion(suggestions[0]);

                                    return;
                                }

                                submit();
                            }

                            // Esc stops a live answer (and keeps the bubble open).
                            if (e.key === 'Escape' && busy && onStop) {
                                e.preventDefault();
                                e.stopPropagation();
                                onStop();
                            }

                            if (e.key === 'Tab' && suggestions.length > 0) {
                                e.preventDefault();
                                applySuggestion(suggestions[0]);
                            }
                        }}
                        rows={1}
                        placeholder={
                            asset
                                ? `Pregunta sobre ${assetDisplay(asset)} o sobre la flota…`
                                : 'Pregunta por una unidad (T555), ubicación, combustible, pánicos… Usa @ para mencionar una unidad'
                        }
                        aria-label="Pregunta para SAM Copilot"
                        className="min-h-10 w-full resize-none border-none bg-transparent px-3 pt-2.5 pb-1 text-lg text-fg-1 outline-none placeholder:text-fg-3 sm:text-sm"
                    />
                    <div className="flex items-center gap-1.5 px-2 pb-2">
                        {asset ? (
                            <span className="inline-flex max-w-[60%] items-center gap-1.5 rounded-full border border-ai-accent/40 bg-ai-accent-bg py-0.5 pr-1 pl-2 text-2xs text-ai-accent">
                                <AssetIcon asset={asset} />
                                <button
                                    type="button"
                                    onClick={() => setPickerOpen(true)}
                                    className="cursor-pointer truncate font-mono font-semibold"
                                    title="Cambiar unidad"
                                >
                                    {assetDisplay(asset)}
                                </button>
                                <button
                                    type="button"
                                    aria-label="Quitar unidad"
                                    onClick={() => onAssetChange(null)}
                                    className="grid size-4 cursor-pointer place-items-center rounded-full hover:bg-ai-accent/15"
                                >
                                    <X className="size-3" />
                                </button>
                            </span>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setPickerOpen(true)}
                                disabled={assets.length === 0}
                                className="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-dashed border-border-strong px-2 py-0.5 text-2xs text-fg-3 hover:text-fg-1 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <Truck className="size-3" /> Elegir unidad
                            </button>
                        )}
                        <span className="flex-1" />
                        {!compact && (
                            <span className="hidden font-mono text-3xs text-fg-3 sm:inline">
                                ⏎ enviar · ⇧⏎ línea · Tab autocompleta
                            </span>
                        )}
                        {/* One control for both states: send ↔ stop. It is
                            the primary "busy / done" signal of the chat. */}
                        <button
                            type="button"
                            onClick={busy ? stopFromButton : submit}
                            onFocus={() => {
                                buttonFocusedRef.current = true;
                            }}
                            onBlur={() => {
                                buttonFocusedRef.current = false;
                            }}
                            disabled={busy ? !onStop : !canSend}
                            aria-label={busy ? 'Detener respuesta' : 'Enviar'}
                            title={busy ? 'Detener (Esc)' : undefined}
                            className={cn(
                                'grid size-8 shrink-0 place-items-center rounded-md transition-[transform,background-color,color] duration-(--motion-fast) ease-(--ease-out)',
                                busy
                                    ? 'cursor-pointer bg-fg-1 text-background hover:bg-fg-2 active:scale-97'
                                    : canSend
                                      ? 'cursor-pointer bg-primary text-primary-foreground hover:bg-primary/90 active:scale-97'
                                      : 'bg-surface-3 text-fg-3',
                            )}
                        >
                            <span
                                key={busy ? 'stop' : 'send'}
                                className="sam-copilot-fade grid place-items-center"
                            >
                                {busy ? (
                                    <Square className="size-3 fill-current" />
                                ) : (
                                    <ArrowUp className="size-4" />
                                )}
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function AssetIcon({ asset }: { asset: CopilotAssetOption }) {
    const Icon = asset.category === 'trailer' ? Container : Truck;

    return (
        <span className="relative inline-flex shrink-0">
            <Icon className="size-3" />
            {asset.status && STATUS_DOT[asset.status] && (
                <span
                    className={cn(
                        'absolute -right-0.5 -bottom-0.5 size-1.5 rounded-full ring-1 ring-surface-1',
                        STATUS_DOT[asset.status],
                    )}
                />
            )}
        </span>
    );
}

function AssetPickerPopover({
    assets,
    title,
    onPick,
    onClose,
}: {
    assets: CopilotAssetOption[];
    title: string;
    onPick: (asset: CopilotAssetOption) => void;
    onClose: () => void;
}) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<'all' | 'vehicle' | 'trailer'>(
        'all',
    );
    const ref = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                onClose();
            }
        };
        document.addEventListener('mousedown', onDown);

        return () => document.removeEventListener('mousedown', onDown);
    }, [onClose]);

    const filtered = useMemo(() => {
        const needle = normalize(query);

        return assets
            .filter((a) => category === 'all' || a.category === category)
            .filter(
                (a) =>
                    needle === '' ||
                    normalize(a.code ?? '').includes(needle) ||
                    normalize(a.name).includes(needle),
            )
            .slice(0, 60);
    }, [assets, query, category]);

    const hasTrailers = assets.some((a) => a.category === 'trailer');

    return (
        <div
            ref={ref}
            // Esc closes only the picker: handled here (React bubbling) and
            // stopped, so the bubble's own Esc never sees it.
            onKeyDown={(e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    onClose();
                }
            }}
            className="absolute inset-x-0 bottom-full z-30 mb-1.5 flex max-h-80 flex-col overflow-hidden rounded-lg border border-border-strong bg-surface-1 shadow-xl motion-safe:animate-[sam-copilot-in_var(--motion-fast)_var(--ease-out)_both]"
        >
            <div className="flex items-center gap-2 border-b border-border px-3 py-2">
                <span className="flex-1 text-xs font-semibold text-fg-1">
                    {title}
                </span>
                <button
                    type="button"
                    aria-label="Cerrar"
                    onClick={onClose}
                    className="grid size-6 cursor-pointer place-items-center rounded-sm text-fg-3 hover:bg-surface-2"
                >
                    <X className="size-3.5" />
                </button>
            </div>
            <div className="flex items-center gap-2 border-b border-border px-3 py-1.5">
                <Search className="size-3.5 text-fg-3" />
                <input
                    autoFocus
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && filtered[0]) {
                            e.preventDefault();
                            onPick(filtered[0]);
                        }
                    }}
                    placeholder="Buscar por número económico o nombre…"
                    className="h-7 flex-1 border-none bg-transparent text-lg text-fg-1 outline-none placeholder:text-fg-3 sm:text-xs"
                />
                {hasTrailers && (
                    <div className="flex gap-0.5 rounded-md bg-surface-2 p-0.5">
                        {(
                            [
                                ['all', 'Todas'],
                                ['vehicle', 'Tractos'],
                                ['trailer', 'Remolques'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => setCategory(value)}
                                className={cn(
                                    'cursor-pointer rounded-sm px-1.5 py-0.5 text-3xs font-medium',
                                    category === value
                                        ? 'bg-surface-1 text-fg-1 shadow-xs'
                                        : 'text-fg-3',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                )}
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto py-1">
                {filtered.length === 0 && (
                    <div className="px-3 py-4 text-center text-xs text-fg-3">
                        Sin unidades que coincidan.
                    </div>
                )}
                {filtered.map((option) => (
                    <button
                        key={option.id}
                        type="button"
                        onClick={() => onPick(option)}
                        className="flex w-full cursor-pointer items-center gap-2.5 px-3 py-1.5 text-left text-xs hover:bg-surface-2"
                    >
                        <AssetIcon asset={option} />
                        <span className="w-20 shrink-0 truncate font-mono font-semibold text-fg-1">
                            {assetDisplay(option)}
                        </span>
                        <span className="truncate text-fg-3">
                            {option.name}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}
