#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="${VERIFY_SKY_BASE_DIR:-/var/www/verifysky}"
REPO_DIR="${BASE_DIR}/repository"
CURRENT_DIR="${BASE_DIR}/current"
RELEASES_DIR="${BASE_DIR}/releases"
SHARED_DIR="${BASE_DIR}/shared"
EXPECTED_SHA="${VERIFY_SKY_EXPECTED_SHA:-}"

cd "${REPO_DIR}"

if [[ -n "${EXPECTED_SHA}" ]]; then
  actual_full_sha="$(git rev-parse HEAD)"
  if [[ "${actual_full_sha}" != "${EXPECTED_SHA}" ]]; then
    echo "[deploy] ERROR: repository is at ${actual_full_sha}, expected ${EXPECTED_SHA}" >&2
    exit 1
  fi
fi

short_sha="$(git rev-parse --short HEAD)"
release_dir="${RELEASES_DIR}/$(date +%Y%m%d-%H%M%S)-${short_sha}"

echo "[deploy] Creating release ${release_dir}"
mkdir -p "${release_dir}"

rsync -a --delete \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='storage' \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='public/build' \
  "${REPO_DIR}/dashboard/" "${release_dir}/dashboard/"

ln -sfn "${SHARED_DIR}/.env" "${release_dir}/dashboard/.env"
rm -rf "${release_dir}/dashboard/storage"
ln -sfn "${SHARED_DIR}/storage" "${release_dir}/dashboard/storage"
rm -rf "${release_dir}/dashboard/public/storage"
ln -sfn "${SHARED_DIR}/storage/app/public" "${release_dir}/dashboard/public/storage"
printf '%s\n' "${short_sha}" > "${release_dir}/REVISION"

sudo chown -R www-data:www-data "${release_dir}"

cd "${release_dir}/dashboard"

echo "[deploy] Installing PHP dependencies"
sudo -u www-data env COMPOSER_HOME="${SHARED_DIR}/storage/.composer" \
  composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

if [[ -f package-lock.json ]]; then
  echo "[deploy] Building frontend assets"
  sudo -u www-data env HOME="${SHARED_DIR}/storage" npm ci --no-audit --no-fund
  sudo -u www-data env HOME="${SHARED_DIR}/storage" npm run build
fi

echo "[deploy] Optimizing Laravel"
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

echo "[deploy] Activating release"
ln -sfn "${release_dir}" "${CURRENT_DIR}"
sudo -u www-data php "${CURRENT_DIR}/dashboard/artisan" queue:restart
sudo systemctl reload php8.4-fpm
sudo systemctl restart verifysky-queue.service

echo "[deploy] Purging Cloudflare cache"
sudo -u www-data php <<'PHP'
<?php
$env = parse_ini_file('/var/www/verifysky/shared/.env', false, INI_SCANNER_RAW);
$token = trim((string) ($env['CLOUDFLARE_API_TOKEN'] ?? ''));
$zone = trim((string) ($env['CLOUDFLARE_ZONE_ID'] ?? ''));

if ($token === '' || $zone === '') {
    fwrite(STDERR, "[deploy] ERROR: Cloudflare token or zone id is missing.\n");
    exit(1);
}

$ch = curl_init('https://api.cloudflare.com/client/v4/zones/'.$zone.'/purge_cache');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode(['purge_everything' => true], JSON_UNESCAPED_SLASHES),
]);

$raw = curl_exec($ch);
if ($raw === false) {
    fwrite(STDERR, '[deploy] ERROR: Cloudflare purge request failed: '.curl_error($ch)."\n");
    exit(1);
}

$json = json_decode($raw, true);
if (! is_array($json) || ! ($json['success'] ?? false)) {
    fwrite(STDERR, '[deploy] ERROR: Cloudflare purge was rejected: '.$raw."\n");
    exit(1);
}

echo "[deploy] Cloudflare cache purge completed.\n";
PHP

echo "[deploy] Done: ${short_sha}"
