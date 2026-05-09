# Edge Cost Baseline Holding Pattern

## Status

- Holding pattern started: 2026-05-08 18:43:58 UTC
- Baseline window: 24-72 hours
- Baseline commit: `a210981`
- Production release observed: `20260508-195509-a210981`
- Worker production deploy observed: `verifysky-edge` version `edf812d7-ca19-4591-9f83-27cb477e1634`
- First production cost sync after outcome rollout: `Rows: 2`

## Hard Freeze

Do not change these areas during the baseline window:

- Caching strategy
- JWT/session logic
- Pass-path write behavior
- Rate-limit logic
- Challenge logic
- Protection classification logic
- Any code intended to reduce D1 or KV usage

The goal is to collect organic outcome-level cost data before optimization.

## Allowed Work

Only operational observation is allowed:

- Run Cloudflare cost sync.
- Read admin outcome breakdown.
- Record baseline metrics.
- Fix production breakage only if needed.

## Stage 2 Holding Pattern

- Started: 2026-05-09 after production deploy `47014bd`.
- Active flag: `ES_MEMORY_CONFIG_CACHE=on`.
- Still disabled:
  - `ES_ZERO_WRITE_PASS=off`
  - `ES_STATELESS_CLEARANCE=off`
- Do not advance to Stage 3 until organic WAE data confirms:
  - `pass_config_cache_hit` is materially higher than `pass_config_cache_miss`
  - `pass_kv_reads / pass requests` drops materially from the prior baseline
  - no spike appears in `challenge_issued` or `blocked`
- Recommended wait: 12-24 hours of organic traffic.

## Production Commands

Run Laravel commands on the production host as `www-data`:

```sh
cd /var/www/verifysky/current/dashboard
sudo -u www-data php artisan billing:sync-cloudflare-costs -v
```

Deployment protocol remains immutable:

```sh
cd /var/www/verifysky/repository
git fetch origin main
git pull --ff-only origin main
```

Do not push from production.

## Admin Review

Use the admin tenant view and inspect the Cloudflare cost attribution outcome breakdown.

Record these metrics for each meaningful domain:

- Total requests by outcome
- `pass` share of total requests
- `pass` estimated cost
- `pass` cost per million requests
- `pass` D1 reads
- `pass` D1 writes
- `pass` KV reads
- `pass` KV writes
- `pass` KV write bytes
- Challenge and blocked outcome costs for comparison

## Decision Gate

Do not begin the Zero-Write Passthrough epic until the baseline answers these questions:

- Is `pass` the dominant traffic outcome?
- Is the high cost per million isolated to challenge outcomes or also present in `pass`?
- Which counters dominate `pass` cost: D1 reads, D1 writes, KV reads, KV writes, or WAE?
- Are there enough organic requests to trust the sample?

## Next Epic Candidate

If baseline confirms `pass` carries material D1/KV write cost, the next epic is:

`Zero-Write Passthrough`

Target:

- Move normal pass traffic toward stateless signed-cookie validation.
- Preserve existing protection behavior.
- Measure margin impact on Starter after rollout.
