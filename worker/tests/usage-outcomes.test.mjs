import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const usageMeter = await readFile(new URL("../src/usage-meter.ts", import.meta.url), "utf8");
const index = await readFile(new URL("../src/index.ts", import.meta.url), "utf8");
const challenge = await readFile(new URL("../src/challenge.ts", import.meta.url), "utf8");

test("usage outcomes are explicit and not derived from HTTP status", () => {
  for (const outcome of [
    "pass",
    "challenge_issued",
    "challenge_passed",
    "challenge_failed",
    "blocked",
  ]) {
    assert.match(usageMeter, new RegExp(`"${outcome}"`));
  }

  assert.doesNotMatch(usageMeter, /outcomeFor\(/);
  assert.doesNotMatch(usageMeter, /status\s*>=/);
  assert.match(usageMeter, /blobs:\s*\[\s*this\.domain,\s*this\.environmentName\(\),\s*this\.outcome,\s*\]/s);
  assert.match(usageMeter, /this\.outcome === "pass" \? this\.counters\.d1RowsWritten : 0/);
  assert.match(usageMeter, /this\.outcome === "pass" \? this\.counters\.configCacheMiss : 0/);
});

test("zero-write passthrough flags and stateless clearance hooks are present", () => {
  for (const flag of [
    "ES_ZERO_WRITE_PASS",
    "ES_STATELESS_CLEARANCE",
    "ES_MEMORY_CONFIG_CACHE",
    "ES_PASS_CLEARANCE_TTL_SECONDS",
    "ES_MEMORY_CONFIG_CACHE_MAX_KEYS",
  ]) {
    assert.match(index, new RegExp(flag));
  }

  assert.match(index, /issuePassClearanceCookie/);
  assert.match(index, /runtimeBundleMemoryCache\.clear\(\)/);
  assert.match(challenge, /clr:\s*"challenge_passed"/);
});

test("origin, challenge, and block paths mark the expected outcomes", () => {
  assert.match(index, /markUsageOutcome\(env,\s*"pass"\)/);
  assert.match(index, /markUsageOutcome\(env,\s*"challenge_issued"\)/);
  assert.match(index, /markUsageOutcome\(env,\s*"challenge_failed"\)/);
  assert.match(index, /markUsageOutcome\(env,\s*"blocked"\)/);

  assert.match(challenge, /markUsageOutcome\(env,\s*"challenge_issued"\)/);
  assert.match(challenge, /markUsageOutcome\(env,\s*"challenge_passed"\)/);
  assert.match(challenge, /markUsageOutcome\(env,\s*"challenge_failed"\)/);
  assert.match(challenge, /markUsageOutcome\(env,\s*"blocked"\)/);
});
