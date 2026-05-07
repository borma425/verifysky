# VerifySky Platform Blueprint (Dashboard + Worker)

## 1) Purpose

This is the single source of truth for how the full VerifySky platform works:

- `dashboard/` (Laravel control plane)
- `worker/` (Cloudflare edge enforcement plane)

This document explains architecture, data contracts, onboarding, security boundaries, operations, and incident handling.

---

## 2) Monorepo Structure

- `dashboard/`: Laravel 12 admin app.
- `worker/`: Cloudflare Worker (TypeScript).
- `docs/blueprints/`: system architecture references.
- `scripts/`: monorepo bootstrap/utility scripts.

Key references:

- Root overview: [README.md](/opt/lampp/htdocs/verifysky/README.md)
- Dashboard readme: [README_DASHBOARD.md](/opt/lampp/htdocs/verifysky/dashboard/README_DASHBOARD.md)
- Deployment gate: [DEPLOYMENT_CHECKLIST.md](/opt/lampp/htdocs/verifysky/docs/DEPLOYMENT_CHECKLIST.md)
- Worker config: [wrangler.toml](/opt/lampp/htdocs/verifysky/worker/wrangler.toml)
- Worker schema: [schema.sql](/opt/lampp/htdocs/verifysky/worker/schema.sql)

---

## 3) System Architecture

The platform is split into two planes:

1. **Control Plane (Dashboard)**
   CRUD, orchestration, policies, settings, ops actions.

2. **Enforcement Plane (Worker)**
   Real-time risk analysis, challenge issuance, blocking, session fast-pass.

Data/storage boundaries:

- **D1**: operational/security/domain data (`domain_configs`, `security_logs`, etc.)
- **KV (`SESSION_KV`)**: fast session/nonce/rate/cache keys
- **Dashboard DB (local Laravel DB)**: internal settings and users

---

## 4) Cloudflare for SaaS Model

The system uses Cloudflare for SaaS with this contract:

1. Customer hostnames are provisioned as **Custom Hostnames** inside VerifySky zone.
2. Customer DNS points (typically `www`) to:
   - `customers.verifysky.com` (`SAAS_CNAME_TARGET`)
3. Worker routes are created/maintained per domain.
4. Domain-level settings (zone/sitekey/secret/status) are stored in `domain_configs`.

Important rule:

- Default onboarding is `www`-first for DNS compatibility.
- Apex support is optional/advanced depending on provider ALIAS/ANAME/flattening.

---

## 5) Dashboard Architecture (Laravel)

## 5.1 Layering Rules

- Controllers are thin (HTTP only).
- Validation in FormRequests.
- Business workflows in Actions/Services.
- Query/data orchestration in repositories/services.
- Blade is presentation only.
- JS lives in `resources/js/*`, no inline UI scripts.

## 5.2 Main Dashboard Modules

1. **Domains**
   - Controller: [DomainsController.php](/opt/lampp/htdocs/verifysky/dashboard/app/Http/Controllers/DomainsController.php)
   - Requests: `app/Http/Requests/Domains/*`
   - Actions: `app/Actions/Domains/*`

2. **Logs**
   - Controller: [LogsController.php](/opt/lampp/htdocs/verifysky/dashboard/app/Http/Controllers/LogsController.php)
   - Repository: [SecurityLogRepository.php](/opt/lampp/htdocs/verifysky/dashboard/app/Repositories/SecurityLogRepository.php)
   - Actions: `app/Actions/Logs/*`
   - ViewData: [LogsIndexViewData.php](/opt/lampp/htdocs/verifysky/dashboard/app/ViewData/LogsIndexViewData.php)

3. **Firewall**
   - Controller: [FirewallRulesController.php](/opt/lampp/htdocs/verifysky/dashboard/app/Http/Controllers/FirewallRulesController.php)
   - Actions: `app/Actions/Firewall/*`
   - ViewData: [FirewallIndexViewData.php](/opt/lampp/htdocs/verifysky/dashboard/app/ViewData/FirewallIndexViewData.php)

4. **Sensitive Paths**
   - Controller: [SensitivePathsController.php](/opt/lampp/htdocs/verifysky/dashboard/app/Http/Controllers/SensitivePathsController.php)

5. **Settings / Sync**
   - Controller: [SettingsController.php](/opt/lampp/htdocs/verifysky/dashboard/app/Http/Controllers/SettingsController.php)
   - Settings model: [DashboardSetting.php](/opt/lampp/htdocs/verifysky/dashboard/app/Models/DashboardSetting.php)

## 5.3 EdgeShield Facade Pattern

`EdgeShieldService` is a compatibility facade with delegated traits:

- [EdgeShieldService.php](/opt/lampp/htdocs/verifysky/dashboard/app/Services/EdgeShieldService.php)
- `EdgeShieldDomainAndSaasFacade`
- `EdgeShieldFirewallAndIpFacade`
- `EdgeShieldInfrastructureFacade`

Specialized services:

- `SaasHostnameService`
- `WorkerRouteService`
- `TurnstileService`
- `SaasSecurityService`
- `FirewallRuleService`
- `WorkerSecretSyncService`
- `WorkerAdminClient`
- `D1DatabaseClient`
- `CloudflareApiClient`

---

## 6) Worker Architecture (Cloudflare Edge)

Main entry:

- [worker/src/index.ts](/opt/lampp/htdocs/verifysky/worker/src/index.ts)

Core worker modules:

- Types/contracts: [types.ts](/opt/lampp/htdocs/verifysky/worker/src/types.ts)
- Risk scoring: [risk.ts](/opt/lampp/htdocs/verifysky/worker/src/risk.ts)
- Challenge flow: [challenge.ts](/opt/lampp/htdocs/verifysky/worker/src/challenge.ts)
- AI defense: [ai-defense.ts](/opt/lampp/htdocs/verifysky/worker/src/ai-defense.ts)
- Shared utilities: `utils.ts`, `crypto.ts`

## 6.1 Worker Request Pipeline

Typical runtime path:

1. Resolve domain + config from D1 (with KV cache).
2. Apply IP/domain allow/block/custom firewall rules.
3. Validate existing `es_session` fast-pass cookie.
4. Score request (0..100) using behavioral + historical + network signals.
5. Dispatch:
   - Normal: pass
   - Suspicious: challenge page
   - Malicious: hard block
6. Run async AI defense pipeline via `ctx.waitUntil`.

## 6.2 Challenge + Session Security Invariants

- `target_x` never sent to client raw.
- challenge nonce is single-use and short-lived.
- telemetry is validated server-side.
- Turnstile verified server-side.
- session token signed and bound to context.

---

## 7) Data Contracts (D1)

Defined in:

- [worker/schema.sql](/opt/lampp/htdocs/verifysky/worker/schema.sql)

Main tables:

1. `domain_configs`
2. `security_logs`
3. `challenges`
4. `fingerprints`
5. `ip_access_rules`
6. `custom_firewall_rules`
7. `sensitive_paths`

Critical `domain_configs` fields:

- `domain_name`
- `zone_id`
- `turnstile_sitekey`
- `turnstile_secret`
- `custom_hostname_id`
- `hostname_status`
- `ssl_status`
- `status`
- `thresholds_json`

---

## 8) End-to-End Domain Onboarding Flow

1. Admin submits domain from dashboard.
2. Dashboard validates request.
3. Dashboard provisions or refreshes Cloudflare custom hostname.
4. Dashboard ensures Turnstile widget.
5. Dashboard ensures worker routes + SaaS security rules.
6. Dashboard updates `domain_configs`.
7. Tenant sets DNS record (`www` CNAME to `customers.verifysky.com`).
8. Status refresh confirms `hostname_status/ssl_status`.
9. Worker starts enforcing policy on that hostname.

---

## 9) Settings and Secret Sync Flow

Source of truth for control-plane settings:

- Dashboard settings table (`DashboardSetting`)

Sync service:

- `WorkerSecretSyncService::syncFromDashboardSettings()`

Steps:

1. Load required secrets/settings.
2. `wrangler secret put` for each secret.
3. Deploy worker vars.
4. Sync routes for active domains.
5. Return detailed logs/errors to dashboard.

---

## 10) Security Boundaries

Hard rules:

1. No secrets in Blade/JS/client payloads.
2. Admin endpoints behind auth + role middleware.
3. Login path sanitized and protected.
4. Secrets are read from config/settings backend, not exposed to frontend.
5. Worker admin token required for `/es-admin/*` operations.

---

## 11) Quality Gates

The project constitution is the governing quality standard for all feature work:

- Security-first separation between the dashboard control plane and Worker
  enforcement plane.
- No unencrypted PII, secrets, tokens, credentials, or tenant operational data in
  storage, logs, caches, exports, or client payloads.
- Normal user-facing API responses below 200 ms.
- At least 80% project test coverage, with documented exceptions only when
  approved with compensating verification.
- PSR-12/Pint for PHP and ESLint for lintable JavaScript/TypeScript source.
- Feature flags or equivalent configuration for risky or incomplete behavior.
- Documentation and modular design for all control/enforcement-plane contracts.

Dashboard gates (must pass):

```bash
cd dashboard
composer quality
```

Includes:

- Pint (`composer lint`)
- PHPStan (`composer analyze`)
- line-limit check (`MAX_SOURCE_LINES=300`)
- tests

Worker verification:

```bash
cd worker
npm run typecheck
npm run build
```

Repository quick checks:

```bash
cd /opt/lampp/htdocs/verifysky
scripts/setup_monorepo.sh verify
```

---

## 12) Operations Runbook (High Signal)

### 12.1 Health Checks

1. Dashboard routes load (`php artisan route:list`).
2. Dashboard tests pass.
3. Worker typecheck/build pass.
4. D1 schema exists and is current.
5. Active domains have valid `domain_configs`.

### 12.2 Common Incidents

1. **Domain stuck pending**
   - Check DNS target
   - Check custom hostname status
   - Refresh from dashboard

2. **Worker route missing**
   - Re-run route sync
   - Verify worker name in config
   - Check Cloudflare route conflicts

3. **Challenge failures spike**
   - Inspect `security_logs`
   - Validate Turnstile keys/secret
   - Verify challenge context and token checks

4. **Settings sync failed**
   - Check token/account id
   - Verify wrangler runtime
   - re-run sync and inspect returned logs

---

## 13) Development Policy

1. Keep backward compatibility in routes and payload contracts.
2. Prefer strangler refactor with delegation.
3. Any endpoint mutation requires FormRequest.
4. Any risky flow change requires targeted tests.
5. Any risky or incomplete behavior requires a feature flag or equivalent
   configuration with rollback/deactivation steps.
6. Any file approaching 520 lines should be evaluated for split before 600.

---

## 14) Canonical References

- Dashboard routes: [routes/web.php](/opt/lampp/htdocs/verifysky/dashboard/routes/web.php)
- Dashboard config: [config/edgeshield.php](/opt/lampp/htdocs/verifysky/dashboard/config/edgeshield.php)
- Worker config: [wrangler.toml](/opt/lampp/htdocs/verifysky/worker/wrangler.toml)
- Worker schema: [schema.sql](/opt/lampp/htdocs/verifysky/worker/schema.sql)

If code behavior and docs differ, treat runtime behavior as source-of-truth, then update this file immediately.
