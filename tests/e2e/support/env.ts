import { execFileSync } from 'node:child_process';
import path from 'node:path';

/**
 * Entorno aislado de las pruebas E2E: SQLite en archivo, cola `sync` (el
 * pipeline completo corre dentro de la petición del webhook), sin sockets ni
 * llaves de IA/Twilio. Nada de esto toca la base ni los servicios de dev.
 */
export const ROOT = path.resolve(import.meta.dirname, '../../..');

export const PORT = Number(process.env.E2E_PORT ?? 8123);

export const BASE_URL = `http://127.0.0.1:${PORT}`;

/** Base que usa el servidor; se restaura antes de cada test. */
export const DATABASE = path.join(ROOT, 'database/e2e.sqlite');

/** Copia limpia tras el sembrado (E2eSeeder). */
export const DATABASE_SNAPSHOT = path.join(ROOT, 'database/e2e.seeded.sqlite');

export const AUTH_DIR = path.join(ROOT, 'tests/e2e/.auth');

export const E2E_ENV: Record<string, string> = {
    APP_ENV: 'local',
    APP_URL: BASE_URL,
    APP_LOCALE: 'es',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: DATABASE,
    DB_URL: '',
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'null',
    MAIL_MAILER: 'array',
    OPENAI_API_KEY: '',
    ANTHROPIC_API_KEY: '',
    GEMINI_API_KEY: '',
    TWILIO_ACCOUNT_SID: '',
    TWILIO_AUTH_TOKEN: '',
    PULSE_ENABLED: 'false',
    TELESCOPE_ENABLED: 'false',
    NIGHTWATCH_ENABLED: 'false',
    PHP_CLI_SERVER_WORKERS: '4',
};

export function artisan(...args: string[]): string {
    return execFileSync('php', ['artisan', ...args], {
        cwd: ROOT,
        env: { ...process.env, ...E2E_ENV },
        encoding: 'utf8',
    });
}
