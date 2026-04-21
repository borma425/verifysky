#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAX_SOURCE_LINES="${MAX_SOURCE_LINES:-600}"

INCLUDE_DIRS=("app" "resources" "routes" "config")
INCLUDE_EXTENSIONS=("php" "blade.php" "js" "ts")

violations=()

for dir in "${INCLUDE_DIRS[@]}"; do
  while IFS= read -r -d '' file; do
    lines=$(wc -l <"$file" | tr -d ' ')
    if (( lines > MAX_SOURCE_LINES )); then
      violations+=("$lines:$file")
    fi
  done < <(
    find "${ROOT_DIR}/${dir}" -type f \
      \( -name "*.php" -o -name "*.blade.php" -o -name "*.js" -o -name "*.ts" \) \
      -print0
  )
done

if ((${#violations[@]} > 0)); then
  echo "Line limit check failed (MAX_SOURCE_LINES=${MAX_SOURCE_LINES})."
  printf '%s\n' "${violations[@]}" | sort -nr
  exit 1
fi

echo "Line limit check passed (MAX_SOURCE_LINES=${MAX_SOURCE_LINES})."
