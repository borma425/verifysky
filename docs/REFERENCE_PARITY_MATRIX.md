# Reference Parity Matrix

Reference project: `/opt/lampp/htdocs/cloudflare_antibots`

This file tracks security-console parity so VerifySky does not expose fields that cannot be saved or skip reference capabilities during tenant-aware implementation.

## Firewall

- Actions: `managed_challenge`, `challenge`, `js_challenge`, `block`, `block_ip_farm`, `allow`.
- Fields: `ip.src`, `ip.src.country`, `ip.src.asnum`, `http.request.uri.path`, `http.request.method`, `http.user_agent`.
- Operators: `eq`, `ne`, `in`, `not_in`, `contains`, `not_contains`, `starts_with`.
- Required capabilities: create, edit, pause/enable, delete, bulk delete, AI/manual split, IP Farm conversion, allow-rule cleanup.
- VerifySky tenant rules must carry `tenant_id` and `scope`; platform global rules must not appear inside tenant consoles.

## IP Farm

- Stored as `custom_firewall_rules` with `[IP-FARM]` description, `action = block`, `field = ip.src`, `operator = in`, and no expiry.
- Required capabilities for users and tenant admins: create ready farm, append targets, edit full farm, pause/enable, remove selected targets, delete selected farms, delete a farm.
- Target parsing accepts IP and CIDR, removes duplicates, and chunks farms at 500 targets.
- User actions stay tenant-scoped and plan-limited; admin tenant actions bypass plan limits.

## Sensitive Paths

- Required capabilities: bulk create rows, exact/contains/ends_with matching, hard block vs challenge split, single unlock, bulk unlock.
- User `global` means all domains in the current tenant. Admin tenant `global` means all domains in that tenant. Platform global is separate.

## Logs

- Required capabilities: filtering, farm status indicators, allow IP, block IP, clear logs, and cleanup of stale farm/block state when allow-listing.
- Tenant users must only act on their own domain logs. Admin tenant console should expose the same actions through tenant-scoped views.

## Admin Tenant Console

- Required areas: Domains, Firewall, Sensitive Paths, IP Farm, Logs, Billing, Account.
- Admin controls must include all user controls plus account suspend/resume/delete and domain status/delete/cache/sync operations.

## Prioritized Backlog (Reference-Driven)

- **P1 (Current hotfix):** Query-layer visual isolation for firewall lists (`description IS NULL OR description NOT LIKE '[IP-FARM]%'`) plus Global naming parity in Firewall/IP Farm UI labels.
- **P2:** Complete wording parity pass for security console copy so all user-facing scope labels prefer `Global` terminology while keeping internal `tenant` keys untouched.
- **P3:** Additional reference-aligned UX refinements for admin/customer mirror consistency and clearer scope indicators across read-only security views.
