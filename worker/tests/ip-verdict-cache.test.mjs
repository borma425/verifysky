import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const index = await readFile(new URL("../src/index.ts", import.meta.url), "utf8");

function functionBody(name) {
  const start = index.indexOf(`function ${name}`);
  assert.notEqual(start, -1, `${name} should exist`);
  const nextFunction = index.indexOf("\nfunction ", start + 1);
  const nextAsyncFunction = index.indexOf("\nasync function ", start + 1);
  const candidates = [nextFunction, nextAsyncFunction].filter((value) => value !== -1);
  const end = candidates.length ? Math.min(...candidates) : index.length;
  return index.slice(start, end);
}

function asyncFunctionBody(name) {
  const start = index.indexOf(`async function ${name}`);
  assert.notEqual(start, -1, `${name} should exist`);
  const nextFunction = index.indexOf("\nfunction ", start + 1);
  const nextAsyncFunction = index.indexOf("\nasync function ", start + 1);
  const candidates = [nextFunction, nextAsyncFunction].filter((value) => value !== -1);
  const end = candidates.length ? Math.min(...candidates) : index.length;
  return index.slice(start, end);
}

test("domain key variants only add www for apex-style hostnames", () => {
  const variantBody = functionBody("getDomainKeyVariants");
  const apexBody = functionBody("looksLikeApexHostname");

  assert.match(variantBody, /looksLikeApexHostname\(baseDomain\)/);
  assert.match(variantBody, /looksLikeApexHostname\(normalized\)/);
  assert.doesNotMatch(variantBody, /normalized\.includes\("\."\)/);
  assert.match(apexBody, /labels\.length === 2/);
  assert.match(index, /"co\.uk"/);
});

test("allow and ban checks use the shared in-memory ip verdict cache", () => {
  assert.match(index, /const IP_VERDICT_CACHE_TTL_MS = 30 \* 1000/);
  assert.match(index, /const ipVerdictCache = new Map<string, IpVerdictCacheEntry>\(\)/);

  const allowBody = asyncFunctionBody("isAdminAllowedIP");
  const banBody = asyncFunctionBody("isTemporarilyBanned");
  const cacheBody = asyncFunctionBody("cachedIpVerdict");

  assert.match(allowBody, /cachedIpVerdict\("allow"/);
  assert.match(banBody, /cachedIpVerdict\("ban"/);
  assert.match(cacheBody, /ipVerdictCache\.get\(cacheKey\)/);
  assert.match(cacheBody, /setIpVerdictCache\(cacheKey, verdict\)/);
  assert.match(cacheBody, /variants\.map\(\(name\) => readVariant\(name\)\)/);
});

test("admin cleanup removes domain-scoped and legacy IP state", () => {
  const cleanupBody = asyncFunctionBody("cleanupIpRuntimeState");
  const adminBody = asyncFunctionBody("handleAdminIPRoute");

  assert.match(cleanupBody, /getTempBanKey\(name, ip\)/);
  assert.match(cleanupBody, /getTrustedIpKey\(name, ip\)/);
  assert.match(cleanupBody, /getFailureRateKey\(name, ip\)/);
  assert.match(cleanupBody, /getDailyVisitKey\(name, ip\)/);
  assert.match(cleanupBody, /`vc:\$\{name\}:\$\{ip\}:`/);
  assert.match(cleanupBody, /IP_ATTACK_DAY_PREFIX/);
  assert.match(cleanupBody, /IP_ATTACK_MONTH_PREFIX/);
  assert.match(cleanupBody, /ipVerdictCache\.clear\(\)/);
  assert.match(adminBody, /path === "\/es-admin\/ip\/cleanup"/);
  assert.match(adminBody, /cleanupIpRuntimeState\(env, ip, domains, \{ removeAllow: true \}\)/);
});
