import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const index = await readFile(new URL("../src/index.ts", import.meta.url), "utf8");
const challenge = await readFile(new URL("../src/challenge.ts", import.meta.url), "utf8");
const utils = await readFile(new URL("../src/utils.ts", import.meta.url), "utf8");

test("security log dedupe uses isolate memory with a bounded 60 second TTL", () => {
  assert.match(utils, /const SECURITY_LOG_DEDUPE_TTL_MS = 60 \* 1000/);
  assert.match(utils, /const SECURITY_LOG_DEDUPE_MAX_KEYS = 10000/);
  assert.match(utils, /const securityLogDedupeCache = new Map<string, number>\(\)/);
  assert.match(utils, /const securityLogTenantContext = new WeakMap<object, string>\(\)/);
  assert.match(utils, /export function bindSecurityLogTenantContext/);
  assert.match(utils, /export function shouldWriteSecurityLogToD1/);
});

test("security log dedupe normalizes volatile details and keeps important audit events", () => {
  assert.match(utils, /replace\(\/\\d\+\/g,\s*"#"\)/);
  assert.match(utils, /"session_created"/);
  assert.match(utils, /"challenge_solved"/);
  assert.match(utils, /"waf_rule_created"/);
});

test("index security logging gates only the D1 security_logs insert", () => {
  assert.match(index, /shouldWriteSecurityLogToD1/);
  assert.match(index, /bindSecurityLogTenantContext\(env, domainConfig\.tenant_id\)/);
  assert.match(
    index,
    /if \(!shouldWriteSecurityLogToD1\(env, domainName, meta\.ip, eventType, details\)\) {\s*return;\s*}\s*try {\s*await env\.DB\.prepare\(\s*`INSERT INTO security_logs/s
  );
  assert.match(index, /function handleHardBlock\(env: Env\): Response {\s*markUsageOutcome\(env, "blocked"\)/s);
});

test("challenge security logging and direct ban logs are deduped without skipping ban action", () => {
  assert.match(challenge, /shouldWriteSecurityLogToD1/);
  assert.match(
    challenge,
    /if \(!shouldWriteSecurityLogToD1\(env, domainName, meta\.ip, eventType, details\)\) {\s*return;\s*}\s*try {\s*await env\.DB\.prepare\(\s*`INSERT INTO security_logs/s
  );
  assert.match(challenge, /await env\.SESSION_KV\.put\(banKey, "1", { expirationTtl: banTtl }\)/);
  assert.match(
    challenge,
    /if \(shouldWriteSecurityLogToD1\(env, domainName, ip, "hard_block", details\)\) {\s*await env\.DB\.prepare\(\s*`INSERT INTO security_logs/s
  );
});
