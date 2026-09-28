# Entorno de agentes — SAM (setup 2026-09-28)

Qué se cambió en el entorno de Claude Code para este repo, por qué, y cómo revertir cada pieza.
Métricas y método: [`BENCHMARK.md`](BENCHMARK.md). Todos los respaldos tienen sufijo de fecha `2026-09-28`.

## Resumen

| Fase | Cambio | Alcance |
|---|---|---|
| 1 | Agentes propios de usuario archivados (−6,9k tokens de contexto fijo) | usuario |
| 2 | `CLAUDE.md` raíz compactado (301 → 69 líneas); reglas por zona en `app/`, `database/`, `tests/`, `resources/js/`; §8 (rutina) movida a `ROUTINE_PROMPT.md` | repo |
| 3 | Plugins LSP (`php-lsp`, `typescript-lsp`) + `intelephense` global | usuario |
| 4 | CodeGraph (MCP + hook + CLI) — **probado y retirado** tras el benchmark | — |
| 5 | `.claude/settings.json` de proyecto (permisos + hook Pint), subagente `tenant-isolation-reviewer`, poda de worktrees mergeados, limpieza de `settings.local.json`, plugin `data` y conectores MCP ajenos desactivados | repo + usuario |

## Lo instalado hoy

### Repo (sin commitear todavía)

| Archivo | Qué hace |
|---|---|
| `CLAUDE.md` | versión compacta: stack, comandos, mapa, invariantes, git. Carga las reglas por zona bajo demanda |
| `app/CLAUDE.md` · `database/CLAUDE.md` · `tests/CLAUDE.md` · `resources/js/CLAUDE.md` | reglas específicas de cada zona (checklist de tenant y patrones de `TenantContext` en `app/`) |
| `ROUTINE_PROMPT.md` | recibe el antiguo §8 de `CLAUDE.md` (contrato de la rutina, inactiva desde 2026-06-10) |
| `.claude/settings.json` | `allow` de comandos del gate (test, pint, wayfinder, npm checks, git de lectura); `ask` para git destructivo; `deny` de lectura/edición de `.env*` y `auth.json`; hook `PostToolUse` que corre Pint sobre cada `.php` editado |
| `.claude/agents/tenant-isolation-reviewer.md` | subagente (Sonnet, sólo lectura) que revisa un diff buscando fugas cross-tenant; se invoca a mano antes de abrir PR |
| `.githooks/post-merge` + `scripts/prune-merged-worktrees.sh` | tras cada `git pull`, poda worktrees de `.claude/worktrees/` ya mergeados, limpios y sin lock vivo; reporta el resto. Activo vía `git config core.hooksPath .githooks` |
| `docs/ai/BENCHMARK.md` · `docs/ai/bench-stats.sh` | protocolo, claves de respuesta, resultados y script de métricas por sesión |
| `.claude/settings.local.json` (ignorado) | se quitaron ~25 permisos acumulados y amplios (`Bash(git *)`, `Bash(php artisan *)`, `Bash(node *)`, `gh api`, `git push`…); se añadió `"enabledPlugins": {"data@synced": false}` |

### Usuario (`~/.claude/`, `~/.claude.json`)

| Cambio | Dónde |
|---|---|
| Agentes `architect-agent`, `dev-agent`, `docs-memory-agent`, `pm-orchestrator`, `pr-code-reviewer` movidos fuera | `~/.claude/agents.bak-2026-09-28/` |
| `php-lsp` y `typescript-lsp` habilitados | `~/.claude/settings.json` → `enabledPlugins` |
| `intelephense` (servidor LSP de PHP) | `npm i -g intelephense` (nvm, Node 24) |
| Conectores claude.ai desactivados en este proyecto: Claude Docs, Google Cloud BigQuery, Supabase | `~/.claude.json` → `projects[<repo>].disabledMcpServers` |
| CodeGraph: MCP, permiso `mcp__codegraph__*`, sección de `~/.claude/CLAUDE.md`, CLI, índice `.codegraph/`, `codegraph.json`, hook `UserPromptSubmit` — **todo eliminado** | ver respaldos `*.bak-codegraph-2026-09-28` |

Se mantiene: MCP de proyecto `samsara-dev-rel`, plugins `superpowers` y `warp`, skill de proyecto `worktree-bootstrap`.

## Cómo revertir

Cada fila es independiente. `R` = raíz del repo.

| Qué | Comando |
|---|---|
| `CLAUDE.md` al estado de `main` (301 líneas, con §8) | `git -C R checkout -- CLAUDE.md ROUTINE_PROMPT.md && rm R/{app,database,tests,resources/js}/CLAUDE.md` (equivale a `CLAUDE.md.bak`) |
| `CLAUDE.md` a antes de la Fase 5 (sin mención del hook Pint) | `cp R/CLAUDE.md.bak-fase5-2026-09-28 R/CLAUDE.md` |
| Settings de proyecto (permisos + hook Pint) | `rm R/.claude/settings.json` |
| `settings.local.json` con los permisos viejos y plugin `data` activo | `cp R/.claude/settings.local.json.bak-fase5-2026-09-28 R/.claude/settings.local.json` |
| Subagente de aislamiento | `rm R/.claude/agents/tenant-isolation-reviewer.md` |
| Poda automática de worktrees | `git -C R config --unset core.hooksPath` (y opcional `rm R/.githooks/post-merge R/scripts/prune-merged-worktrees.sh`) |
| Agentes de usuario archivados | `mv ~/.claude/agents.bak-2026-09-28/*.md ~/.claude/agents/` |
| Plugins LSP | `/plugin` → desactivar `php-lsp` y `typescript-lsp` (o quitarlos de `enabledPlugins`); `npm rm -g intelephense` |
| Conectores claude.ai (p. ej. Claude Docs) | `/mcp` → habilitar el conector (quita la entrada de `disabledMcpServers`) |
| Todo `~/.claude.json` a como estaba al empezar el día | `cp ~/.claude.json.bak-2026-09-28 ~/.claude.json` (cerrar antes todas las sesiones de Claude; hay puntos intermedios `.bak-codegraph-…` y `.bak-fase5-…`) |
| Todo `~/.claude/settings.json` a como estaba al empezar el día | `cp ~/.claude/settings.json.bak-2026-09-28 ~/.claude/settings.json` |
| Reinstalar CodeGraph | no recomendado (ver resultados). Respaldos: `R/.claude/settings.json.bak-codegraph-2026-09-28` (hook), `R/codegraph.json.bak-codegraph-2026-09-28`, `R/.gitignore.bak-codegraph-2026-09-28` (`/.codegraph`), `~/.claude/CLAUDE.md.bak-codegraph-2026-09-28`, `~/.claude/settings.json.bak-codegraph-2026-09-28` (permiso MCP) |

Los cambios de repo surten efecto en una sesión nueva de `claude`; los de `~/.claude.json` requieren cerrar todas las sesiones antes de restaurar el archivo (Claude lo reescribe al salir).

## Resultados del benchmark

4 tareas reales × 4 configuraciones, headless, `claude-opus-5-5`, n=1 por celda. Detalle por celda y claves en [`BENCHMARK.md`](BENCHMARK.md#resultados).

| Config | Costo total (4 tareas) | Calidad | Uso de la herramienta nueva |
|---|---|---|---|
| base | $2,75 | 4/4 ✅ | — |
| +LSP | $2,75 | 4/4 ✅ | `LSP`: 0 usos en 4 corridas |
| +CodeGraph | $2,79 | 4/4 ✅ | `codegraph_explore`: 2 usos (sólo tarea a) |
| +CodeGraph+hook | $3,01 (+8 %) | 3/4 ✅, 1 🟡 (conteo de modelos erróneo) | 1 uso; hook inyecta ~16 KB irrelevantes |

**Conclusiones**

- Ninguna herramienta de navegación mejoró precisión, costo ni velocidad de forma medible; la varianza entre corridas idénticas (hasta 2× en tool calls) es mayor que cualquier diferencia entre configs.
- **CodeGraph retirado**: el modelo lo ignora a favor de grep y el hook empeora costo y calidad.
- **LSP se conserva**: coste de contexto ~0 y su valor (diagnósticos tras editar) no lo mide este benchmark.
- Lo que sí movió la aguja fue **reducir el contexto fijo**: 56,4k tokens al inicio del día (memoria 18,3k · tools 16,9k · skills 7,1k · agentes 6,9k · MCP 1,7k). Fase 1 quitó 6,9k; Fase 2 dejó el `CLAUDE.md` raíz en 69 líneas; Fase 5 quita CodeGraph (~2k), skills de `data` (~1,1k) y sus MCPs.
- Riesgo detectado: dos corridas **editaron la memoria persistente** sin pedirlo (revertido). Revisar `memory/` tras tareas que renombran o auditan.

### Contexto fijo tras la Fase 5

| Momento | Total `/context` (sesión vacía) |
|---|---|
| Antes de cualquier cambio | 56,4k |
| Tras Fase 5 | _pendiente: correr `/context` en sesión nueva y anotarlo_ |

## Pendiente

- Anotar el `/context` de una sesión nueva (tabla de arriba).
- Commitear en rama `chore/…` y abrir PR: `CLAUDE.md` + zonas, `ROUTINE_PROMPT.md`, `.claude/settings.json`, `.claude/agents/`, `.githooks/`, `scripts/prune-merged-worktrees.sh`, `docs/ai/`. No incluir los `*.bak*` ni `.playwright-cli/`.
- Tras verificar una semana sin problemas, borrar los `*.bak*` del repo y de `~/.claude*`.
