import { MessageSquarePlus, Plug, Sparkles } from 'lucide-react';

const DATA_SOURCES = [
    'Unidades',
    'GPS',
    'Telemetría',
    'Media',
    'Incidentes',
    'Conductores',
];

export interface CopilotPageHeaderProps {
    teamName: string | undefined;
    engineLabel: string;
    onNew: () => void;
}

export function CopilotPageHeader({
    teamName,
    engineLabel,
    onNew,
}: CopilotPageHeaderProps) {
    return (
        <header className="flex shrink-0 flex-wrap items-center gap-3 border-b border-border px-4 py-3 sm:px-6">
            <div className="grid size-9 place-items-center rounded-md bg-ai-accent-bg text-ai-accent">
                <Sparkles className="size-4.5" />
            </div>
            <div className="min-w-0 flex-1">
                <h1 className="text-sm font-semibold text-fg-1">SAM Copilot</h1>
                <p className="truncate font-mono text-3xs text-fg-3">
                    Conectado a {teamName} · {engineLabel}
                </p>
            </div>
            <div className="hidden items-center gap-2 rounded-full border border-border bg-surface-2 px-3 py-1 text-2xs text-fg-2 lg:flex">
                <Plug className="size-3" />
                {DATA_SOURCES.map((source, index) => (
                    <span key={source} className="flex items-center gap-2">
                        {index > 0 && (
                            <span className="size-0.75 rounded-full bg-fg-3" />
                        )}
                        {source}
                    </span>
                ))}
            </div>
            <button
                type="button"
                onClick={onNew}
                className="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-xs text-fg-2 hover:bg-surface-2 md:hidden"
            >
                <MessageSquarePlus className="size-3.5" /> Nueva
            </button>
        </header>
    );
}
