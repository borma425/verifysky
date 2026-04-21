# Edge Shield Admin Dashboard (Laravel 12)

This is a separate Laravel 12 project to administrate the Cloudflare Edge Shield worker.

## Architecture Blueprint

Primary technical reference:

- `../docs/blueprints/BLUEPRINT_PLATFORM.md`
- `../docs/DEPLOYMENT_CHECKLIST.md`

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
- `DASHBOARD_LOGIN_PATH`
- `EDGE_SHIELD_ROOT` (path to worker project)
- `WRANGLER_BIN` (default: `npx wrangler`)
- `CLOUDFLARE_API_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_KV_NAMESPACE_ID`
- `D1_DATABASE_ID`
- `PAYPAL_CLIENT_ID`
- `PAYPAL_SECRET`
- `PAYPAL_WEBHOOK_ID`

Important separation:

- `.env` configures Laravel, backend access to Cloudflare/Wrangler, and payment provider credentials.

## D1 Schema Bootstrap

Run `php artisan edgeshield:d1:schema-sync` after provisioning a new local D1 database or after deploying schema changes. Schema reconciliation no longer runs inside HTTP requests.
- Worker runtime secrets such as `jwt_secret`, `meter_secret`, and `es_admin_token` are synced from the dashboard settings store.

## Run Locally

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
scripts/setup_edge_shield_runtime.sh all
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8090
```

Then open:

`http://localhost:8090/wow/login`
## Security Notes

- Rotate dashboard admin password immediately.
- Use HTTPS and IP allowlist when deploying publicly.
- Move secrets to a real vault/KMS for production.
- Restrict server user permissions for command execution.
- Keep the PayPal webhook endpoint public, but rely on PayPal signature verification only.
- Treat `dashboard_settings` as a secrets store and secure database access accordingly.

## Engineering Quality Gates

- Source line cap is enforced with `MAX_SOURCE_LINES=600`.
- Validation script:

```bash
bash scripts/check-line-limits.sh
```

- CI/local quality command:

```bash
composer quality
```

This runs:
- `composer lint` (`pint --test`)
- `composer analyze` (`phpstan analyse`)
- `composer line-limit-check`
- `composer test`

## Server Migration Bootstrap

When moving to a new server, run:

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
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

## Production Pre-Flight

Before a public launch:

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
php artisan migrate --force
php artisan queue:work --queue=default --tries=3
php artisan schedule:run
composer quality
```

Production also requires:

- a continuously running queue worker
- a system cron entry for `schedule:run`
- successful `Settings -> Cloudflare sync`
- valid KV and D1 production bindings

Use `../docs/DEPLOYMENT_CHECKLIST.md` as the final release gate.
