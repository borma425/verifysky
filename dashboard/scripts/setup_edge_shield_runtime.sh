#!/usr/bin/env bash
set -euo pipefail

# Edge Shield bootstrap script (server migration friendly)
# - Installs local Node runtime under dashboard/.runtime
# - Ensures dashboard .env uses local Node/Wrangler paths
# - Installs npm/composer dependencies when missing
# - Verifies wrangler runtime can execute without XAMPP lib conflicts

SCRIPT_DIR="$(cd -P "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DASHBOARD_ROOT="$(cd -P "${SCRIPT_DIR}/.." && pwd)"
DEFAULT_WORKER_ROOT="$(cd "${DASHBOARD_ROOT}/../worker" && pwd 2>/dev/null || true)"

ACTION="${1:-all}" # install | verify | all
NODE_VERSION="${NODE_VERSION:-v22.22.1}"
NODE_DISTRO="${NODE_DISTRO:-linux-x64}"
NODE_TAR="node-${NODE_VERSION}-${NODE_DISTRO}.tar.xz"
NODE_URL="https://nodejs.org/dist/${NODE_VERSION}/${NODE_TAR}"
RUNTIME_DIR="${DASHBOARD_ROOT}/.runtime"
NODE_HOME="${RUNTIME_DIR}/node-${NODE_VERSION}"
NODE_BIN_DIR="${NODE_HOME}/bin"
WORKER_ROOT="${EDGE_SHIELD_ROOT:-${DEFAULT_WORKER_ROOT}}"
ENV_FILE="${DASHBOARD_ROOT}/.env"

log() { printf '[edge-shield-setup] %s\n' "$*"; }
die() { printf '[edge-shield-setup] ERROR: %s\n' "$*" >&2; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

upsert_env() {
  local key="$1"
  local value="$2"

  touch "${ENV_FILE}"
  if grep -Eq "^${key}=" "${ENV_FILE}"; then
    sed -i "s#^${key}=.*#${key}=${value}#g" "${ENV_FILE}"
  else
    printf '%s=%s\n' "${key}" "${value}" >> "${ENV_FILE}"
  fi
}

install_node_runtime() {
  require_cmd tar
  require_cmd curl

  mkdir -p "${RUNTIME_DIR}"

  if [[ -x "${NODE_BIN_DIR}/node" && -x "${NODE_BIN_DIR}/npx" ]]; then
    log "Node runtime already present: ${NODE_HOME}"
    return
  fi

  local tmp_tar
  tmp_tar="$(mktemp "${RUNTIME_DIR}/node-runtime.XXXXXX.tar.xz")"
  log "Downloading Node runtime: ${NODE_URL}"
  curl -fsSL "${NODE_URL}" -o "${tmp_tar}"

  local extract_dir
  extract_dir="$(mktemp -d "${RUNTIME_DIR}/extract.XXXXXX")"
  tar -xJf "${tmp_tar}" -C "${extract_dir}"
  rm -f "${tmp_tar}"

  local unpacked="${extract_dir}/node-${NODE_VERSION}-${NODE_DISTRO}"
  [[ -d "${unpacked}" ]] || die "Unexpected archive layout for ${NODE_TAR}"

  rm -rf "${NODE_HOME}"
  mv "${unpacked}" "${NODE_HOME}"
  rm -rf "${extract_dir}"

  [[ -x "${NODE_BIN_DIR}/node" ]] || die "Node binary not found after extraction"
  [[ -x "${NODE_BIN_DIR}/npx" ]] || die "npx binary not found after extraction"
  log "Installed Node runtime to ${NODE_HOME}"
}

install_dependencies() {
  require_cmd php

  if [[ -f "${DASHBOARD_ROOT}/composer.json" ]]; then
    if [[ ! -d "${DASHBOARD_ROOT}/vendor" ]]; then
      require_cmd composer
      log "Installing dashboard composer dependencies"
      (cd "${DASHBOARD_ROOT}" && composer install --no-interaction --prefer-dist)
    fi
  fi

  if [[ -f "${DASHBOARD_ROOT}/package-lock.json" ]]; then
    log "Installing dashboard npm dependencies"
    (cd "${DASHBOARD_ROOT}" && PATH="${NODE_BIN_DIR}:${PATH}" npm ci --no-audit --no-fund)
  fi

  if [[ -n "${WORKER_ROOT}" && -d "${WORKER_ROOT}" && -f "${WORKER_ROOT}/package-lock.json" ]]; then
    log "Installing worker npm dependencies"
    (cd "${WORKER_ROOT}" && PATH="${NODE_BIN_DIR}:${PATH}" npm ci --no-audit --no-fund)
  fi
}

configure_dashboard_env() {
  [[ -n "${WORKER_ROOT}" && -d "${WORKER_ROOT}" ]] || die "Worker root not found: ${WORKER_ROOT}"

  local wrangler_bin="${NODE_BIN_DIR}/npx wrangler"
  upsert_env "EDGE_SHIELD_ROOT" "${WORKER_ROOT}"
  upsert_env "NODE_BIN_DIR" "${NODE_BIN_DIR}"
  upsert_env "WRANGLER_BIN" "\"${wrangler_bin}\""

  log "Updated dashboard env file: ${ENV_FILE}"
  log "  EDGE_SHIELD_ROOT=${WORKER_ROOT}"
  log "  NODE_BIN_DIR=${NODE_BIN_DIR}"
  log "  WRANGLER_BIN=${wrangler_bin}"
}

verify_runtime() {
  [[ -x "${NODE_BIN_DIR}/node" ]] || die "Node binary missing: ${NODE_BIN_DIR}/node"
  [[ -x "${NODE_BIN_DIR}/npx" ]] || die "npx binary missing: ${NODE_BIN_DIR}/npx"
  [[ -d "${WORKER_ROOT}" ]] || die "Worker root missing: ${WORKER_ROOT}"

  local clean_path
  clean_path="${NODE_BIN_DIR}:/usr/local/bin:/usr/bin:/bin"
  local env_prefix
  env_prefix="env -u LD_LIBRARY_PATH -u LD_PRELOAD -u LIBRARY_PATH LD_LIBRARY_PATH='' LD_PRELOAD='' LIBRARY_PATH='' PATH='${clean_path}'"

  log "Verifying node runtime"
  bash -lc "${env_prefix} node -v"
  bash -lc "${env_prefix} npm -v"

  log "Verifying wrangler runtime"
  (cd "${WORKER_ROOT}" && bash -lc "${env_prefix} \"${NODE_BIN_DIR}/npx\" wrangler --version")

  log "Verifying worker TypeScript compile"
  (cd "${WORKER_ROOT}" && bash -lc "${env_prefix} npm run -s typecheck")

  log "Verifying dashboard PHP syntax"
  php -l "${DASHBOARD_ROOT}/app/Services/EdgeShieldService.php" >/dev/null
  php -l "${DASHBOARD_ROOT}/app/Http/Controllers/SettingsController.php" >/dev/null

  log "Verify completed successfully"
}

usage() {
  cat <<'EOF'
Usage:
  scripts/setup_edge_shield_runtime.sh [install|verify|all]

Optional env overrides:
  EDGE_SHIELD_ROOT=/path/to/cloudflare_antibots/worker
  NODE_VERSION=v22.22.1
  NODE_DISTRO=linux-x64
EOF
}

case "${ACTION}" in
  install)
    install_node_runtime
    configure_dashboard_env
    install_dependencies
    ;;
  verify)
    verify_runtime
    ;;
  all)
    install_node_runtime
    configure_dashboard_env
    install_dependencies
    verify_runtime
    ;;
  -h|--help|help)
    usage
    ;;
  *)
    usage
    die "Invalid action: ${ACTION}"
    ;;
esac

log "Done"
