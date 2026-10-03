export function InboxFooter({
    count,
    total,
    canAssign,
}: {
    count: number;
    total: number;
    canAssign: boolean;
}) {
    return (
        <div className="flex shrink-0 items-center justify-between border-t border-border bg-surface-1 px-5 py-2">
            <span className="text-2xs text-fg-3">
                {count} de {total} incidentes
            </span>
            {/* D2: los atajos de teclado no aplican en táctil; se ocultan en
                pantallas pequeñas. */}
            <div className="hidden items-center gap-2 font-mono text-3xs text-fg-3 md:flex">
                <span className="sam-kbd">J</span>
                <span className="sam-kbd">K</span>
                <span>navegar</span>
                {canAssign && (
                    <>
                        <span className="sam-kbd ml-2">A</span>
                        <span>asignar</span>
                    </>
                )}
                <span className="sam-kbd ml-2">X</span>
                <span>seleccionar</span>
                <span className="sam-kbd ml-2">Enter</span>
                <span>abrir</span>
            </div>
        </div>
    );
}
