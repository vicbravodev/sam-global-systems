import { Loader2, X } from 'lucide-react';

export function DetailPlaceholder({
    loading,
    onClose,
}: {
    loading: boolean;
    onClose: () => void;
}) {
    return (
        <div className="relative flex min-w-0 flex-col items-center justify-center gap-3 border-l border-border bg-background p-8 text-center">
            <button
                type="button"
                onClick={onClose}
                className="absolute top-3 right-3 text-fg-3 hover:text-fg-1"
                aria-label="Cerrar detalle"
            >
                <X size={16} />
            </button>
            {loading ? (
                <>
                    <Loader2 size={22} className="animate-spin text-fg-3" />
                    <span className="text-xs text-fg-3">Cargando detalle…</span>
                </>
            ) : (
                <span className="text-xs text-fg-3">
                    No se pudo cargar el detalle del incidente.
                </span>
            )}
        </div>
    );
}
