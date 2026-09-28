#!/usr/bin/env bash
# Métricas de una sesión de Claude Code para docs/ai/BENCHMARK.md.
#
# Uso (desde una terminal normal, DESPUÉS de salir de la sesión medida):
#   docs/ai/bench-stats.sh              # la sesión más reciente de este repo (incluye worktrees)
#   docs/ai/bench-stats.sh <session-id> # una sesión concreta
#
# Muestra el inicio del primer prompt para que confirmes que es la sesión correcta.
# Suma los subagentes que esa sesión haya lanzado.

set -euo pipefail

projects=~/.claude/projects

if [[ $# -ge 1 ]]; then
    file=$(ls "$projects"/*sam-global-systems*/"$1".jsonl 2>/dev/null | head -1)
else
    file=$(ls -t "$projects"/*sam-global-systems*/*.jsonl 2>/dev/null | head -1)
fi

[[ -n "${file:-}" && -f "$file" ]] || { echo "No encontré la sesión." >&2; exit 1; }

id=$(basename "$file" .jsonl)
subagents="${file%.jsonl}/subagents"

prompt=$(jq -r 'select(.type=="user") | .message.content
    | if type=="string" then . else ([.[]? | select(.type=="text") | .text] | join(" ")) end
    | select(length > 0 and (test("^\\s*<") | not)) | gsub("\\s+"; " ")' "$file" 2>/dev/null | head -1 | cut -c1-90)

cat "$file" "$subagents"/*.jsonl 2>/dev/null | jq -s --arg id "$id" --arg prompt "$prompt" '
  [.[] | select(.type=="assistant")] as $a
  | ($a | group_by(.message.id) | map(.[0].message.usage)) as $u
  | ([.[] | .timestamp // empty] | sort) as $ts
  | {
      session: $id,
      prompt: $prompt,
      tool_calls: ([$a[] | .message.content[]? | select(.type=="tool_use")] | length),
      input_tokens: ($u | map(.input_tokens // 0) | add),
      cache_write_tokens: ($u | map(.cache_creation_input_tokens // 0) | add),
      cache_read_tokens: ($u | map(.cache_read_input_tokens // 0) | add),
      output_tokens: ($u | map(.output_tokens // 0) | add),
      seconds: (($ts[-1] | sub("\\.[0-9]+Z$";"Z") | fromdate) - ($ts[0] | sub("\\.[0-9]+Z$";"Z") | fromdate))
    }'
