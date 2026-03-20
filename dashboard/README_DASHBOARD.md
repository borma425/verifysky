# Edge Shield Admin Dashboard (Laravel 12)

This is a separate Laravel 12 project to administrate the Cloudflare Edge Shield worker.

## What It Manages

- Admin login (simple env-based guard)
- Domain management (`domain_configs` in remote D1)
- Security logs viewer (`security_logs` in remote D1)
- Settings/secrets vault (stored in dashboard local DB)
- Operational actions (`typecheck`, `build`, `deploy`, D1 init) against worker project

## Important Environment Variables

Set these in `.env`:

- `DASHBOARD_ADMIN_USER`
- `DASHBOARD_ADMIN_PASS`
- `EDGE_SHIELD_ROOT` (path to worker project)
- `WRANGLER_BIN` (default: `npx wrangler`)

## Run Locally

```bash
cd /opt/lampp/htdocs/cloudflare_antibots/dashboard
scripts/setup_edge_shield_runtime.sh all
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8090
```

Then open:

`http://localhost:8090/login`

## Security Notes

- Rotate dashboard admin password immediately.
- Use HTTPS and IP allowlist when deploying publicly.
- Move secrets to a real vault/KMS for production.
- Restrict server user permissions for command execution.

## Server Migration Bootstrap

When moving to a new server, run:

```bash
cd /opt/lampp/htdocs/cloudflare_antibots/dashboard
scripts/setup_edge_shield_runtime.sh all
```

This script will:
- Install a local Node runtime under `.runtime/`
- Configure `.env` (`EDGE_SHIELD_ROOT`, `NODE_BIN_DIR`, `WRANGLER_BIN`)
- Install dashboard and worker npm dependencies
- Verify wrangler/node execution with XAMPP-safe environment

You can run only checks later with:

```bash
scripts/setup_edge_shield_runtime.sh verify
```
