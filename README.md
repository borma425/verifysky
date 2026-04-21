# Edge Shield Monorepo

This repository contains:

- `worker/`: Cloudflare Worker project (Edge Shield engine)
- `dashboard/`: Laravel admin dashboard

## Quick Setup

From repo root:

```bash
scripts/setup_monorepo.sh all
```

## Verify Runtime

```bash
scripts/setup_monorepo.sh verify
```

## Worker Commands

```bash
cd worker
npm run -s typecheck
npm run -s build
```

## Notes

- Dashboard-to-Worker sync is automatic on settings save.
- Worker runtime values are sourced from dashboard settings.
- `worker/wrangler.toml` uses `verifysky-edge` as the production default and `[env.staging]` for CLI/CI staging deploys.
- Legacy path `/opt/lampp/htdocs/edge_shield_dashboard` can be symlinked to `dashboard/` for compatibility.

## Architecture Direction

- Dashboard: Laravel control plane for Cloudflare Worker security operations.
- Worker: Cloudflare edge runtime for hostname resolution, challenge handling, and request policy enforcement.
- Dashboard sync targets production only.
- Staging is managed through `wrangler deploy --env staging` and the worker CLI scripts with `--env staging`.

## Architecture Blueprint

- `docs/blueprints/BLUEPRINT_PLATFORM.md`

## Cloudflare for SaaS Domain Onboarding

`customers.verifysky.com` is the VerifySky SaaS fallback hostname and CNAME target. It is not the first customer hostname to add as a Cloudflare Custom Hostname.

For each customer, add the hostname in the VerifySky Domains screen. The universal setup uses `www` for root domains because it works across Cloudflare DNS, Namecheap, GoDaddy, and most other DNS providers.

```text
customer-domain.com      => VerifySky prepares www.customer-domain.com
www.customer-domain.com  => VerifySky prepares www.customer-domain.com
shop.customer-domain.com => VerifySky prepares shop.customer-domain.com
```

The only required customer action is a DNS record at their DNS provider:

```text
www.customer-domain.com CNAME customers.verifysky.com
```

Root-to-www redirect is optional and should not block activation. It can be offered as a later improvement:

```text
customer-domain.com -> https://www.customer-domain.com
```

Root/apex records such as `customer-domain.com -> customers.verifysky.com` are advanced-only. They require `CNAME flattening`, `ALIAS`, or `ANAME` support. Many DNS providers do not allow a normal `CNAME` at the apex because the root domain also has `NS` and `SOA` records.

Do not make apex records or root redirects the default onboarding requirement. Use `www` as the customer-facing default. A redirect from the apex domain to `www` is recommended, but optional.

Operational rule:

- `customers.verifysky.com` stays configured inside the VerifySky Cloudflare zone as the fallback origin and Worker route target.
- Customer hostnames are created as Cloudflare Custom Hostnames inside the VerifySky zone.
- Customers do not add their zones to the VerifySky Cloudflare account.
- Customers point `www` or their chosen subdomain to `customers.verifysky.com`.
