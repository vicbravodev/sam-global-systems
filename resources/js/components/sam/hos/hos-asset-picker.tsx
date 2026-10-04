import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import type { HosAssetOption } from '@/types/hos';

export interface HosAssetPickerProps {
    id: string;
    assets: HosAssetOption[];
    selected: number[];
    /** Unidades ya elegidas en la otra lista: no se ofrecen aquí. */
    taken: number[];
    disabled: boolean;
    placeholder: string;
    /** Aviso por unidad elegida (p. ej. "no vigilada: no entra"). */
    noteFor?: (asset: HosAssetOption) => string | null;
    onChange: (next: number[]) => void;
}

/** Buscador de unidades del team + las elegidas como fichas quitables. */
export function HosAssetPicker({
    id,
    assets,
    selected,
    taken,
    disabled,
    placeholder,
    noteFor,
    onChange,
}: HosAssetPickerProps) {
    const byId = new Map(assets.map((asset) => [asset.id, asset]));
    const blocked = new Set([...selected, ...taken]);
    const options = assets
        .filter((asset) => !blocked.has(asset.id))
        .map((asset) => ({
            value: String(asset.id),
            label: asset.name,
            description: asset.code ?? undefined,
        }));

    return (
        <div className="flex flex-col gap-2">
            <Combobox
                id={id}
                options={options}
                value={null}
                onChange={(value) => {
                    if (value !== null) {
                        onChange([...selected, Number(value)]);
                    }
                }}
                placeholder={placeholder}
                emptyText="No hay más unidades"
                disabled={disabled}
            />
            {selected.length > 0 ? (
                <ul className="flex flex-wrap gap-1.5">
                    {selected.map((assetId) => {
                        const asset = byId.get(assetId) ?? null;

                        return (
                            <AssetChip
                                key={assetId}
                                name={asset?.name ?? `Unidad ${assetId}`}
                                note={asset && noteFor ? noteFor(asset) : null}
                                disabled={disabled}
                                onRemove={() =>
                                    onChange(
                                        selected.filter(
                                            (value) => value !== assetId,
                                        ),
                                    )
                                }
                            />
                        );
                    })}
                </ul>
            ) : null}
        </div>
    );
}

function AssetChip({
    name,
    note,
    disabled,
    onRemove,
}: {
    name: string;
    note: string | null;
    disabled: boolean;
    onRemove: () => void;
}) {
    return (
        <li className="inline-flex items-center gap-1 rounded-md border border-border bg-surface-2 py-0.5 pr-0.5 pl-2 text-xs text-fg-1">
            <span>{name}</span>
            {note ? <span className="text-fg-3">· {note}</span> : null}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-6"
                aria-label={`Quitar ${name}`}
                disabled={disabled}
                onClick={onRemove}
            >
                <X className="size-3.5" />
            </Button>
        </li>
    );
}
