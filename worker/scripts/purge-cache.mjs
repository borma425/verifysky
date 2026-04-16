#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { execSync } from "node:child_process";

const CF_API_BASE = "https://api.cloudflare.com/client/v4";
const D1_DATABASE_NAME = process.env.D1_DATABASE_NAME || "VERIFY_SKY_STAGING_DB";

function loadDotEnvIfNeeded() {
  if (process.env.CLOUDFLARE_API_TOKEN || process.env.CF_API_TOKEN) return;
  const envPath = path.resolve(process.cwd(), ".env");
  if (!fs.existsSync(envPath)) return;
  const raw = fs.readFileSync(envPath, "utf8");
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) continue;
    const idx = trimmed.indexOf("=");
    if (idx <= 0) continue;
    const key = trimmed.slice(0, idx).trim();
    let value = trimmed.slice(idx + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (!(key in process.env)) process.env[key] = value;
  }
}

function extractJsonPayload(stdout) {
  const start = stdout.indexOf("[");
  const end = stdout.lastIndexOf("]");
  if (start === -1 || end === -1 || end <= start) return null;
  const maybe = stdout.slice(start, end + 1);
  try {
    return JSON.parse(maybe);
  } catch {
    return null;
  }
}

function getActiveZoneIds() {
  const sql = "SELECT DISTINCT zone_id FROM domain_configs WHERE status = 'active' AND zone_id IS NOT NULL AND zone_id != ''";
  const cmd = `npx wrangler d1 execute ${D1_DATABASE_NAME} --remote --json --command="${sql}"`;
  const raw = execSync(cmd, {
    cwd: process.cwd(),
    stdio: ["ignore", "pipe", "pipe"],
    timeout: 60000,
  }).toString("utf8");

  const payload = extractJsonPayload(raw);
  if (!payload || !Array.isArray(payload) || !payload.length) return [];

  const rows = payload[0]?.results || [];
  const zoneIds = Array.from(
    new Set(rows.map((row) => String(row.zone_id || "").trim()).filter(Boolean))
  );
  return zoneIds;
}

async function purgeZone(zoneId, apiToken) {
  const resp = await fetch(`${CF_API_BASE}/zones/${zoneId}/purge_cache`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiToken}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ purge_everything: true }),
  });

  let data = null;
  try {
    data = await resp.json();
  } catch {
    data = null;
  }

  if (!resp.ok || !data?.success) {
    const msg = data?.errors?.map((e) => `[${e.code}] ${e.message}`).join(", ") || `HTTP ${resp.status}`;
    throw new Error(msg);
  }
}

async function main() {
  loadDotEnvIfNeeded();
  const apiToken = process.env.CLOUDFLARE_API_TOKEN || process.env.CF_API_TOKEN;
  const strict = String(process.env.ES_PURGE_CACHE_STRICT || "off").toLowerCase() === "on";
  if (!apiToken) {
    console.warn("[purge] Skipped: missing CLOUDFLARE_API_TOKEN / CF_API_TOKEN");
    return;
  }

  let zoneIds = [];
  try {
    zoneIds = getActiveZoneIds();
  } catch (error) {
    console.warn(`[purge] Skipped: failed reading zones from D1: ${error.message}`);
    if (strict) process.exit(1);
    return;
  }

  if (!zoneIds.length) {
    console.log("[purge] No active zones found. Nothing to purge.");
    return;
  }

  console.log(`[purge] Purging cache for ${zoneIds.length} zone(s)...`);
  let failures = 0;
  for (const zoneId of zoneIds) {
    try {
      await purgeZone(zoneId, apiToken);
      console.log(`[purge] OK ${zoneId}`);
    } catch (error) {
      failures += 1;
      console.error(`[purge] FAIL ${zoneId}: ${error.message}`);
    }
  }

  if (failures > 0) {
    console.warn(`[purge] Completed with ${failures} failure(s).`);
    console.warn("[purge] Check Cloudflare token permissions: Zone -> Cache Purge -> Purge.");
    if (strict) process.exit(1);
    return;
  }

  console.log("[purge] Done. All active zones purged.");
}

main().catch((error) => {
  console.error(`[purge] Fatal: ${error.message}`);
  process.exit(1);
});
