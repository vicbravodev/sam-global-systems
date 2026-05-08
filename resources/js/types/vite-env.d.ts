/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_APP_NAME?: string;
    readonly VITE_PUSHER_APP_KEY?: string;
    readonly VITE_PUSHER_HOST?: string;
    readonly VITE_PUSHER_PORT?: string;
    readonly VITE_PUSHER_SCHEME?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
