#!/usr/bin/env bash
# Setup del entorno para la rutina nocturna (clon limpio en cloud, sin Docker).
# Deja el repo listo para: php artisan test --compact (sqlite :memory: vía phpunit.xml),
# vendor/bin/pint, npm run types:check/lint:check/format:check y npm run build.
# Idempotente: se puede correr varias veces.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "==> [1/6] composer install"
composer install --no-interaction --prefer-dist --no-progress

echo "==> [2/6] .env (sqlite + drivers locales, sin Postgres/Valkey/Soketi)"
if [[ ! -f .env ]]; then
    cat > .env <<ENV
APP_NAME="SAM Global Systems"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=4

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

# sqlite local — los tests usan sqlite :memory: definido en phpunit.xml;
# este archivo valida que las migraciones corren y sirve para comandos artisan.
DB_CONNECTION=sqlite
DB_DATABASE=${ROOT}/database/database.sqlite

SESSION_DRIVER=file
SESSION_LIFETIME=120

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
CACHE_STORE=file

MAIL_MAILER=log
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="\${APP_NAME}"

AI_DEFAULT=openai
OPENAI_API_KEY=
OPENAI_TEXT_MODEL=gpt-5.4

PUSHER_APP_ID=sam-local
PUSHER_APP_KEY=sam-key
PUSHER_APP_SECRET=sam-secret
PUSHER_HOST=localhost
PUSHER_PORT=6001
PUSHER_SCHEME=http

VITE_APP_NAME="\${APP_NAME}"
VITE_PUSHER_APP_KEY="\${PUSHER_APP_KEY}"
VITE_PUSHER_HOST="\${PUSHER_HOST}"
VITE_PUSHER_PORT="\${PUSHER_PORT}"
VITE_PUSHER_SCHEME="\${PUSHER_SCHEME}"
ENV
    echo "    .env creado."
else
    echo "    .env ya existe — no se toca."
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --ansi --no-interaction
fi
# No se crea .env.testing: phpunit.xml ya fija APP_ENV=testing + sqlite :memory:
# y el APP_KEY se lee del .env (mismo patrón que el CI).

echo "==> [3/6] sqlite + migraciones"
mkdir -p database
[[ -f database/database.sqlite ]] || touch database/database.sqlite
php artisan migrate --force --no-interaction

echo "==> [4/6] git hooks del repo (pre-push de cobertura; se salta solo si no hay pcov/xdebug)"
composer run hooks:install --no-interaction || echo "    (no fatal) hooks no instalados"

echo "==> [5/6] npm ci"
npm ci --no-audit --no-fund

echo "==> [6/6] build frontend (genera tipos Wayfinder vía plugin Vite)"
npm run build

echo
echo "✔ Entorno listo. Verificación sugerida:"
echo "    php artisan test --compact"
echo "    vendor/bin/pint --test"
echo "    npm run types:check && npm run lint:check && npm run format:check"
