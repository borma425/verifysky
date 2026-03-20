#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DASHBOARD_DIR="${ROOT_DIR}/dashboard"

if [[ ! -d "${DASHBOARD_DIR}" ]]; then
  echo "[setup-monorepo] ERROR: dashboard directory not found at ${DASHBOARD_DIR}" >&2
  exit 1
fi

ACTION="${1:-all}" # install | verify | all

echo "[setup-monorepo] Running dashboard bootstrap (${ACTION})..."
"${DASHBOARD_DIR}/scripts/setup_edge_shield_runtime.sh" "${ACTION}"

echo "[setup-monorepo] Done"
