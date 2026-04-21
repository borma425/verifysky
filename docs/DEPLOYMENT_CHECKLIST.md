# VerifySky Pre-Flight Launch Manifest

This checklist is the production release gate for the VerifySky platform.

It covers:

- Dashboard control-plane readiness
- Worker/runtime sync prerequisites
- Queue and scheduler automation
- Cloudflare and PayPal production configuration
- End-to-end protection validation before cutover

## 1. Control Plane and Worker Contract

The protection path in production is:

`plan change / payment / manual grant -> effective plan resolution -> billing cycle reset when needed -> PurgeRuntimeBundleCache job -> KV/runtime bundle refresh -> Worker applies new protection state without redeploy`

Important operational rule:

- VerifySky does **not** rely on a Worker redeploy for every plan or protection change.
- Runtime protection changes are propagated through `PurgeRuntimeBundleCache` and KV/runtime bundle refresh.
- If the queue worker is down, dashboard state can change while edge enforcement remains stale.

## 2. Production Infrastructure Requirements

Before launch, confirm all of the following:

- Run database migrations on the dashboard database:

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
php artisan migrate --force
```

- Run a queue worker continuously:

```bash
php artisan queue:work --queue=default --tries=3
```

- Use `systemd` or `supervisor` in production. Do not rely on an interactive shell.
- Run the Laravel scheduler every minute:

```cron
* * * * * php /opt/lampp/htdocs/verifysky/dashboard/artisan schedule:run >/dev/null 2>&1
```

- Confirm the dashboard database contains these operational tables:
  - `jobs`
  - `failed_jobs`
  - `sessions`
  - `cache`
  - `tenant_usage`
  - `tenant_subscriptions`
  - `payment_webhook_events`
  - `tenant_plan_grants`
- Confirm the dashboard host can execute Wrangler against the worker project configured by `EDGE_SHIELD_ROOT`.
- Run the runtime verification script on the production host:

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
scripts/setup_edge_shield_runtime.sh verify
```

- Confirm the Cloudflare KV namespace used for runtime bundle caching exists and matches `CLOUDFLARE_KV_NAMESPACE_ID`.
- Confirm the Worker project resolves the correct D1 production database through `D1_DATABASE_ID` and/or `worker/wrangler.toml`.
- Confirm Worker deploys are pointed at production resources. The checked-in `worker/wrangler.toml` currently names the staging worker/database; production launch requires either a production-specific Wrangler environment/config or explicit CI/CD overrides for worker name, D1 database, KV namespace, and account bindings.

## 3. Required `.env` for Dashboard Production

The dashboard `.env` is responsible for Laravel runtime, worker backend access, and provider credentials.

### Laravel application

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://<dashboard-domain>`
- `APP_KEY=<generated-production-key>`
- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

### Session and security

- `SESSION_DRIVER=database`
- `SESSION_SECURE_COOKIE=true`
- `SESSION_HTTP_ONLY=true`
- `SESSION_DOMAIN=<dashboard-domain>` if required by your deployment topology
- `SESSION_SAME_SITE=lax` unless you have a specific cross-site requirement

### Queue and cache

- `QUEUE_CONNECTION=database` at minimum
- Prefer Redis when available for higher reliability:
  - `QUEUE_CONNECTION=redis`
  - `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, and any queue-specific Redis settings

### EdgeShield / Cloudflare backend access

- `EDGE_SHIELD_ROOT`
- `NODE_BIN_DIR`
- `WRANGLER_BIN`
- `CLOUDFLARE_API_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`
- `CLOUDFLARE_KV_NAMESPACE_ID`
- `CLOUDFLARE_ZONE_ID`
- `D1_DATABASE_NAME`
- `D1_DATABASE_ID`
- `SAAS_CNAME_TARGET`
- `EDGE_SHIELD_WORKER_NAME`

### PayPal live mode

- `PAYPAL_MODE=live`
- `PAYPAL_CLIENT_ID`
- `PAYPAL_SECRET`
- `PAYPAL_WEBHOOK_ID`
- `PAYPAL_PLAN_ID_GROWTH`
- `PAYPAL_PLAN_ID_PRO`
- `PAYPAL_PLAN_ID_BUSINESS`
- `PAYPAL_PLAN_ID_SCALE`

### Admin access

Choose one of these models:

- Set `DASHBOARD_ADMIN_USER` and `DASHBOARD_ADMIN_PASS` to long, unique credentials
- Or rely on database users with `role=admin` and leave env-based admin login disabled

In both cases:

- never ship shared or default credentials
- set `DASHBOARD_LOGIN_PATH` to a non-obvious path

## 4. Required Dashboard Settings Before Worker Sync

These values are not only `.env` concerns. They must exist in the dashboard settings store before a successful Worker sync.

Required:

- `cf_api_token`
- `jwt_secret` with at least 32 characters
- `es_admin_token`

Recommended:

- `meter_secret` with at least 32 characters if metering cookies are enabled
- `openrouter_api_key`
- `openrouter_model`
- `openrouter_fallback_models`
- `es_admin_allowed_ips`
- `es_admin_rate_limit_per_min`
- `es_turnstile_strict=on`
- `es_strict_context_binding=on` if your runtime is ready for the stricter binding mode
- `es_block_redirect_url` if you want a unified block redirect target

Successful worker sync acceptance criterion:

- saving the Settings page must end with `Settings saved and synced to Cloudflare successfully.`

## 5. Scheduled Billing and Protection Automation

The following scheduled commands must be active in production:

- `billing:sync-edge-usage`
- `billing:reconcile-expired-subscriptions`
- `billing:reconcile-expired-plan-grants`

Operational meaning:

- `billing:sync-edge-usage` drives usage metering and pass-through enforcement
- `billing:reconcile-expired-subscriptions` downgrades plans only after the paid period ends
- `billing:reconcile-expired-plan-grants` expires manual beta grants and recalculates effective protection limits

If the scheduler stops, usage drift and delayed downgrade/reset behavior are expected.

## 6. Security Hardening Before Public Launch

- Serve the dashboard behind HTTPS only.
- Put the dashboard behind IP allowlisting or private ingress if feasible.
- Keep the admin login route hidden with `DASHBOARD_LOGIN_PATH`, but do not treat obscurity as sufficient protection.
- Protect the dashboard database as a secrets store because `dashboard_settings` contains operational secrets.
- Keep `POST /webhooks/payments/paypal` public. Its security model is PayPal signature verification, not session auth.
- Register the webhook in PayPal using the exact HTTPS production endpoint.
- Monitor `failed_jobs` and application logs; failures in `PurgeRuntimeBundleCache` or payment webhooks are production incidents.
- Rotate these values before launch if they were ever used in staging:
  - `DASHBOARD_ADMIN_PASS`
  - `JWT_SECRET`
  - `ES_ADMIN_TOKEN`
  - `CLOUDFLARE_API_TOKEN`

## 7. Go / No-Go Validation

Launch is blocked until all checks below pass.

### Check 1: Worker sync

1. Open `Settings`
2. Save the current production values
3. Verify the sync succeeds without Cloudflare or Wrangler errors

Pass condition:

- deploy succeeds
- route sync succeeds

### Check 2: Queue path

1. Trigger an action that dispatches `PurgeRuntimeBundleCache`
   - manual grant
   - force cycle reset
   - domain config update
2. Confirm the job leaves the queue successfully

Pass condition:

- no stuck jobs
- no related row in `failed_jobs`

### Check 3: Plan-to-protection propagation

1. Apply a manual grant to a tenant
2. Confirm the dashboard and billing portal show the new effective plan
3. Confirm a fresh billing cycle is created when required
4. Confirm purge jobs are dispatched for the tenant domains

Pass condition:

- effective plan changes in UI
- billing reset occurs exactly once
- purge reaches all expected domains

### Check 4: Expiry automation

1. Exercise `billing:reconcile-expired-plan-grants` on staging data
2. Exercise `billing:reconcile-expired-subscriptions` on staging data

Pass condition:

- downgrade happens only after `ends_at` or `current_period_ends_at`
- only one reset is triggered for each effective-plan drop

### Check 5: Payment flow

1. Run a final PayPal live or sandbox subscription test
2. Confirm the webhook is recorded and processed

Pass condition:

- one webhook event record per provider event
- tenant plan updates correctly
- billing reset happens without duplicate processing

### Check 6: Quality gate

Run:

```bash
cd /opt/lampp/htdocs/verifysky/dashboard
composer quality
```

Pass condition:

- Pint passes
- PHPStan passes
- line-limit check passes
- tests pass

## 8. Final Readiness Decision

VerifySky is **not** production-ready if any of the following is missing:

- a running queue worker
- a working scheduler
- successful Worker sync from dashboard settings
- valid Cloudflare KV and D1 production binding
- a verified PayPal webhook endpoint

The UI alone is not a readiness signal. Production readiness depends on the control plane, automation plane, and Worker runtime all being live together.
