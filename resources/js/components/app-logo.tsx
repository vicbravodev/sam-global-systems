import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center text-primary">
                <AppLogoIcon className="size-8" />
            </div>
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate text-sm font-semibold tracking-tight text-fg-1">
                    SAM
                </span>
                <span className="font-mono text-[10px] tracking-[0.12em] text-fg-3 uppercase">
                    Operations
                </span>
            </div>
        </>
    );
}
