---
name: worktree-bootstrap
description: Use when entering a newly created git worktree under .claude/worktrees/ before running any gate (types/lint/build/tests/preview) — vendor/, .env and the generated Wayfinder types are gitignored and absent, so artisan, types:check, build and serve all fail until the worktree is bootstrapped. Also covers serving a local preview without Docker.
---

# Bootstrap de un worktree nuevo (OBLIGATORIO antes de correr gates)

Un worktree recién creado bajo `.claude/worktrees/<slug>` **no es ejecutable tal cual**: `vendor/`, `.env` y los tipados generados de Wayfinder están gitignored y no se copian con el checkout. Síntomas: `php artisan` lanza `Failed opening required vendor/autoload.php`; `npm run types:check` / `npm run build` fallan con `Cannot find module '@/routes'` en **todas** las páginas; `php artisan serve` da HTTP 500 por sesión Redis/Valkey. Esto **no es** un bug de la tarea, es estado de worktree. Bootstrap (idempotente; ejecutar al entrar a un worktree nuevo, ANTES de tipos/lint/build/tests/preview):

```bash
MAIN="$(git worktree list --porcelain | grep -m1 '^worktree ' | cut -d' ' -f2)"   # checkout principal

# 1. PHP: vendor no tiene traversal de directorios → symlink (rápido) o composer install.
[ -e vendor ] || ln -s "$MAIN/vendor" vendor
#    ⚠ El SYMLINK sirve para artisan/types/build/tests, pero ROMPE la cobertura
#    local: el autoloader de Composer tiene rutas absolutas al checkout principal,
#    así que las clases App\ se cargan desde MAIN y pcov (apuntando al app/ del
#    worktree) reporta 0%. Para correr cobertura o pasar el pre-push hook hay que
#    tener vendor REAL en el worktree: `rm -f vendor && composer install`
#    (no toca composer.json). Alternativa puntual: `SKIP_COVERAGE=1 git push`
#    sólo si el usuario lo autoriza (la cobertura real la valida CI igual).

# 2. Env: .env gitignored.
[ -f .env ] || cp "$MAIN/.env" .env

# 3. Tipados Wayfinder (resources/js/{routes,actions,wayfinder}) gitignored.
#    Con vendor ya enlazado:
php artisan wayfinder:generate --with-form        # en worktrees SIEMPRE con --with-form
#    (alternativa sin vendor: cp -R "$MAIN/resources/js/"{routes,actions,wayfinder} resources/js/)

# node_modules NO requiere acción: Node resuelve subiendo directorios y encuentra el del checkout principal.
```

Tras el bootstrap, los gates corren normales (`npm run types:check && npm run lint:check && npm run format:check`, `npm run build`, `php artisan test --compact`). `git status` debe seguir mostrando SOLO los archivos de la tarea (todo lo anterior es gitignored).

**Preview en navegador (verificación visual de UI):** la sesión apunta a Valkey/Postgres de Docker (hosts `valkey`/`pgsql`), inaccesibles fuera de compose. Para servir el build local sin Docker, overridear drivers en el comando de serve:

```bash
SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync DB_CONNECTION=sqlite DB_DATABASE=:memory: \
  php artisan serve --port=<puerto>
```

Sirve el build de producción vía manifest, así que tras cada cambio de front hay que `npm run build` y recargar. Limpiar artefactos de preview (`.claude/launch.json` u otros no-entregables) antes de cerrar; `vendor`/`.env`/generados quedan (gitignored, aceleran el siguiente comando).
