#!/usr/bin/env bash
# Poda los worktrees de .claude/worktrees/ cuyo trabajo ya entró a `main`.
#
# Solo elimina un worktree si cumple las tres condiciones:
#   1. su HEAD es ancestro de `main` (ya mergeado),
#   2. no tiene cambios ni archivos sin trackear (los ignorados, p.ej. vendor/, no cuentan),
#   3. no está bloqueado por un proceso vivo (lock "claude agent ... (pid N ...)").
# Nunca toca el worktree actual ni worktrees fuera de .claude/worktrees/.
# Lo que no cumple se reporta como huérfano y se deja intacto.
#
# Uso: scripts/prune-merged-worktrees.sh [--dry-run]
# Se dispara solo desde .githooks/post-merge (tras cada `git pull`).

set -uo pipefail

dry_run=0
[[ "${1:-}" == "--dry-run" ]] && dry_run=1

common_dir=$(git rev-parse --path-format=absolute --git-common-dir) || exit 0
root=$(dirname "$common_dir")
current=$(git rev-parse --show-toplevel)
base=main

git -C "$root" rev-parse --verify --quiet "$base" >/dev/null || exit 0

removed=0

prune_one() {
    local path=$1 head=$2 branch=$3 lock=$4

    [[ "$path" == "$root/.claude/worktrees/"* ]] || return 0
    [[ "$path" == "$current" ]] && return 0

    if [[ ! -d "$path" ]]; then
        return 0 # lo recoge `git worktree prune`
    fi

    local name=${path#"$root/"}

    if ! git -C "$root" merge-base --is-ancestor "$head" "$base" 2>/dev/null; then
        echo "worktrees: $name (${branch:-detached}) tiene commits fuera de $base — se conserva"
        return 0
    fi

    if [[ -n "$(git -C "$path" status --porcelain 2>/dev/null)" ]]; then
        echo "worktrees: $name tiene cambios sin commitear — se conserva (revísalo)"
        return 0
    fi

    if [[ -n "$lock" ]]; then
        local pid
        pid=$(sed -n 's/.*(pid \([0-9][0-9]*\).*/\1/p' <<<"$lock")
        if [[ -z "$pid" ]]; then
            echo "worktrees: $name bloqueado manualmente ($lock) — se conserva"
            return 0
        fi
        if kill -0 "$pid" 2>/dev/null; then
            echo "worktrees: $name en uso por la sesión pid $pid — se conserva"
            return 0
        fi
    fi

    if (( dry_run )); then
        echo "worktrees: [dry-run] eliminaría $name${branch:+ y la rama $branch}"
        return 0
    fi

    [[ -n "$lock" ]] && git -C "$root" worktree unlock "$path" 2>/dev/null
    if git -C "$root" worktree remove "$path" 2>/dev/null; then
        removed=$((removed + 1))
        # -d (no -D): git rechaza si la rama no está mergeada.
        [[ -n "$branch" ]] && git -C "$root" branch -d "$branch" >/dev/null 2>&1
    else
        echo "worktrees: no se pudo eliminar $name — se conserva"
    fi
}

path='' head='' branch='' lock=''
while IFS= read -r line; do
    case "$line" in
        'worktree '*) path=${line#worktree } ;;
        'HEAD '*) head=${line#HEAD } ;;
        'branch refs/heads/'*) branch=${line#branch refs/heads/} ;;
        'locked'*) lock=${line#locked}; lock=${lock# }; [[ -z "$lock" ]] && lock='(sin motivo)' ;;
        '')
            [[ -n "$path" ]] && prune_one "$path" "$head" "$branch" "$lock"
            path='' head='' branch='' lock=''
            ;;
    esac
done < <(git -C "$root" worktree list --porcelain; echo)

(( dry_run )) || git -C "$root" worktree prune
(( removed > 0 )) && echo "worktrees: $removed worktree(s) mergeados eliminados"
exit 0
