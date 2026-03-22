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
- Legacy path `/opt/lampp/htdocs/edge_shield_dashboard` can be symlinked to `dashboard/` for compatibility.

## Architecture Blueprint

- Trap + Project blueprint: [docs/TRAP_BLUEPRINT.md](docs/TRAP_BLUEPRINT.md)
