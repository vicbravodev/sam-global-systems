export type TabKey =
    | 'summary'
    | 'billing'
    | 'members'
    | 'features'
    | 'usage'
    | 'settings';

const TAB_KEYS: TabKey[] = [
    'summary',
    'billing',
    'members',
    'features',
    'usage',
    'settings',
];

export function initialTab(): TabKey {
    if (typeof window === 'undefined') {
        return 'summary';
    }

    const tab = new URLSearchParams(window.location.search).get('tab');

    return TAB_KEYS.includes(tab as TabKey) ? (tab as TabKey) : 'summary';
}

export function syncTabToUrl(key: TabKey): void {
    const url = new URL(window.location.href);

    if (key === 'summary') {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', key);
    }

    if (url.href !== window.location.href) {
        window.history.replaceState(window.history.state, '', url);
    }
}
