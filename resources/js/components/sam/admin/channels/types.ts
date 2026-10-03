export interface PlatformChannel {
    id: number;
    code: string;
    name: string;
    provider: string;
    channelType: string | null;
    isActive: boolean;
    configKeys: string[];
}

export interface ConfigField {
    key: string;
    label: string;
    hint?: string;
    kind?: 'text' | 'secret' | 'json' | 'number';
}

/** A change waiting for confirmation in the dialog. */
export interface PendingChannelAction {
    title: string;
    description: string;
    confirmLabel: string;
    tone: 'destructive' | 'default';
    run: () => Promise<void>;
}

export interface ChannelTypeOption {
    value: string;
    label: string;
}
