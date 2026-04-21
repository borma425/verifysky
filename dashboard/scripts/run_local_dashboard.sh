#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -P "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DASHBOARD_ROOT="$(cd -P "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${DASHBOARD_ROOT}/.env"

log() { printf '[local-dashboard] %s\n' "$*"; }
die() { printf '[local-dashboard] ERROR: %s\n' "$*" >&2; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"
}

need_composer_install() {
  [[ ! -f "${DASHBOARD_ROOT}/vendor/autoload.php" ]] && return 0
  [[ "${DASHBOARD_ROOT}/composer.lock" -nt "${DASHBOARD_ROOT}/vendor/autoload.php" ]] && return 0

  return 1
}

need_npm_install() {
  [[ ! -d "${DASHBOARD_ROOT}/node_modules" ]] && return 0
  [[ "${DASHBOARD_ROOT}/package-lock.json" -nt "${DASHBOARD_ROOT}/node_modules" ]] && return 0

  return 1
}

need_worker_npm_install() {
  [[ ! -d "${DASHBOARD_ROOT}/../worker/node_modules" ]] && return 0
  [[ "${DASHBOARD_ROOT}/../worker/package-lock.json" -nt "${DASHBOARD_ROOT}/../worker/node_modules" ]] && return 0

  return 1
}

ensure_env_file() {
  if [[ ! -f "${ENV_FILE}" ]]; then
    log "Creating .env from .env.example"
    cp "${DASHBOARD_ROOT}/.env.example" "${ENV_FILE}"
  fi
}

ensure_app_key() {
  if ! grep -Eq '^APP_KEY=base64:' "${ENV_FILE}"; then
    log "Generating APP_KEY"
    (cd "${DASHBOARD_ROOT}" && php artisan key:generate --force)
  fi
}

need_seed_demo_access() {
  (cd "${DASHBOARD_ROOT}" && php artisan tinker --execute="
    echo (App\Models\User::query()->where('email', 'admin@verifysky.test')->exists()
      && App\Models\User::query()->where('email', 'user@verifysky.test')->exists()) ? 'yes' : 'no';
  " | tail -n 1) | grep -qx 'no'
}

need_migrate() {
  local output

  output="$(cd "${DASHBOARD_ROOT}" && php artisan migrate:status --pending --no-ansi 2>/dev/null || true)"

  [[ "${output}" == *"Pending"* || "${output}" == *"Migration table not found"* || "${output}" == *"No such table"* ]]
}

main() {
  require_cmd php
  require_cmd composer
  require_cmd npm

  log "Preparing local runtime"
  (cd "${DASHBOARD_ROOT}" && bash scripts/setup_edge_shield_runtime.sh all)

  ensure_env_file

  if need_composer_install; then
    log "Installing PHP dependencies"
    (cd "${DASHBOARD_ROOT}" && composer install --no-interaction)
  else
    log "Skipping composer install; dependencies already present"
  fi

  if need_npm_install; then
    log "Installing dashboard frontend dependencies"
    (cd "${DASHBOARD_ROOT}" && npm install)
  else
    log "Skipping dashboard npm install; node_modules already present"
  fi

  if need_worker_npm_install; then
    log "Installing worker frontend dependencies"
    (cd "${DASHBOARD_ROOT}/../worker" && npm install)
  else
    log "Skipping worker npm install; node_modules already present"
  fi

  ensure_app_key

  if need_migrate; then
    log "Running pending migrations"
    (cd "${DASHBOARD_ROOT}" && php artisan migrate --force)
  else
    log "Skipping migrations; database is up to date"
  fi

  if need_seed_demo_access; then
    log "Seeding demo access accounts"
    (cd "${DASHBOARD_ROOT}" && SEED_RESET_PASSWORDS=true php artisan db:seed --force)
  else
    log "Skipping demo seed; local access accounts already exist"
  fi

  log "Starting local dashboard on http://127.0.0.1:8000/wow/login"
  cd "${DASHBOARD_ROOT}"
  exec composer dev
}

main "$@"
