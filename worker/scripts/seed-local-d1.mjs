#!/usr/bin/env node
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { execFileSync } from "node:child_process";
import { resolveRuntimeTarget } from "./runtime-target.mjs";

const { envName, d1DatabaseName, wranglerEnvArgs } = resolveRuntimeTarget();
const LOCAL_DOMAIN = process.env.ES_LOCAL_TEST_DOMAIN || "www.cashup.cash";
const LOCAL_TENANT_ID = process.env.ES_LOCAL_TEST_TENANT_ID || "1";
const SAMPLE_MARKER = "[LOCAL-E2E]";

function sqlString(value) {
  return String(value).replaceAll("'", "''");
}

const domain = sqlString(LOCAL_DOMAIN.toLowerCase().trim());
const tenantId = sqlString(LOCAL_TENANT_ID);
const marker = sqlString(SAMPLE_MARKER);

const sql = `
INSERT INTO domain_configs (
  domain_name,
  tenant_id,
  zone_id,
  turnstile_sitekey,
  turnstile_secret,
  custom_hostname_id,
  cname_target,
  origin_server,
  hostname_status,
  ssl_status,
  ownership_verification_json,
  force_captcha,
  security_mode,
  status,
  thresholds_json,
  created_at,
  updated_at
) VALUES (
  '${domain}',
  '${tenantId}',
  'local_zone',
  '1x00000000000000000000AA',
  '1x0000000000000000000000000000000AA',
  'local_custom_hostname',
  'customers.verifysky.com',
  'localhost',
  'active',
  'active',
  '{"type":"txt","name":"_verifysky","value":"local-e2e"}',
  0,
  'balanced',
  'active',
  NULL,
  CURRENT_TIMESTAMP,
  CURRENT_TIMESTAMP
)
ON CONFLICT(domain_name) DO UPDATE SET
  tenant_id = excluded.tenant_id,
  zone_id = excluded.zone_id,
  turnstile_sitekey = excluded.turnstile_sitekey,
  turnstile_secret = excluded.turnstile_secret,
  custom_hostname_id = excluded.custom_hostname_id,
  cname_target = excluded.cname_target,
  origin_server = excluded.origin_server,
  hostname_status = excluded.hostname_status,
  ssl_status = excluded.ssl_status,
  ownership_verification_json = excluded.ownership_verification_json,
  force_captcha = excluded.force_captcha,
  security_mode = excluded.security_mode,
  status = excluded.status,
  thresholds_json = excluded.thresholds_json,
  updated_at = CURRENT_TIMESTAMP;

DELETE FROM security_logs WHERE details LIKE '${marker}%';
INSERT INTO security_logs (domain_name, event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details, created_at)
VALUES
  ('${domain}', 'challenge_issued', '203.0.113.10', '64500', 'EG', '/login', 'local-fingerprint-1', 48, '${marker} challenge sample', datetime('now', '-20 minutes')),
  ('${domain}', 'challenge_solved', '203.0.113.10', '64500', 'EG', '/login', 'local-fingerprint-1', 18, '${marker} solved sample', datetime('now', '-18 minutes')),
  ('${domain}', 'hard_block', '198.51.100.44', '64501', 'US', '/wp-login.php', 'local-fingerprint-2', 96, '${marker} block sample', datetime('now', '-8 minutes'));

DELETE FROM custom_firewall_rules WHERE description LIKE '${marker}%';
INSERT INTO custom_firewall_rules (domain_name, description, action, expression_json, paused, expires_at, created_at, updated_at)
VALUES
  ('${domain}', '${marker} Block sample scanner IP', 'block', '{"field":"ip.src","operator":"eq","value":"198.51.100.44"}', 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
  ('global', '${marker} Log suspicious admin path', 'log', '{"field":"http.request.uri.path","operator":"contains","value":"/admin"}', 0, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

DELETE FROM ip_access_rules WHERE note LIKE '${marker}%';
INSERT INTO ip_access_rules (domain_name, ip_or_cidr, action, note, created_at)
VALUES
  ('${domain}', '203.0.113.10', 'allow', '${marker} local allow sample', CURRENT_TIMESTAMP),
  ('${domain}', '198.51.100.44', 'block', '${marker} local block sample', CURRENT_TIMESTAMP);

DELETE FROM sensitive_paths WHERE domain_name = '${domain}' AND path_pattern IN ('/wp-login.php', '/admin');
INSERT INTO sensitive_paths (domain_name, path_pattern, match_type, action, created_at)
VALUES
  ('${domain}', '/wp-login.php', 'exact', 'challenge', CURRENT_TIMESTAMP),
  ('${domain}', '/admin', 'contains', 'challenge', CURRENT_TIMESTAMP);
`;

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), "verifysky-d1-"));
const sqlFile = path.join(tempDir, "local-seed.sql");

try {
  fs.writeFileSync(sqlFile, sql, "utf8");
  execFileSync("npx", ["wrangler", "d1", "execute", d1DatabaseName, ...wranglerEnvArgs, "--local", "--file", sqlFile], {
    cwd: process.cwd(),
    stdio: "inherit",
    timeout: 60000,
  });
  console.log(`[seed-local-d1] Seeded ${envName} local D1 "${d1DatabaseName}" for ${LOCAL_DOMAIN}`);
} finally {
  fs.rmSync(tempDir, { recursive: true, force: true });
}
