import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const index = await readFile(new URL("../src/index.ts", import.meta.url), "utf8");
const challenge = await readFile(new URL("../src/challenge.ts", import.meta.url), "utf8");
const utils = await readFile(new URL("../src/utils.ts", import.meta.url), "utf8");

test("ip farm debounce uses isolate memory with a bounded two minute TTL", () => {
  assert.match(utils, /const IP_FARM_DEBOUNCE_TTL_MS = 120 \* 1000/);
  assert.match(utils, /const IP_FARM_DEBOUNCE_MAX_KEYS = 10000/);
  assert.match(utils, /const ipFarmDebounceCache = new Map<string, number>\(\)/);
  assert.match(utils, /export function shouldRunIpFarmMutation/);
});

test("main IP farm mutation is gated before allow-list and D1 work", () => {
  const start = index.indexOf("async function markForIpFarm");
  const end = index.indexOf("function getIpSubnet", start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const body = index.slice(start, end);

  assert.match(body, /if \(!shouldRunIpFarmMutation\(requestDomain \|\| null, ip\)\) return/);
  assert.ok(
    body.indexOf("shouldRunIpFarmMutation") < body.indexOf("isIpAllowListed"),
    "debounce must happen before allow-list D1 reads"
  );
  assert.ok(
    body.indexOf("shouldRunIpFarmMutation") < body.indexOf("SELECT id, expression_json"),
    "debounce must happen before IP farm D1 scans"
  );
});

test("challenge IP farm mutation is gated before allow-list and D1 work", () => {
  const start = challenge.indexOf("async function addToIpFarm");
  const end = challenge.indexOf("// extractDomainFromMeta", start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const body = challenge.slice(start, end);

  assert.match(body, /if \(!shouldRunIpFarmMutation\(null, ip\)\) return/);
  assert.ok(
    body.indexOf("shouldRunIpFarmMutation") < body.indexOf("isIpAllowListed"),
    "debounce must happen before allow-list D1 reads"
  );
  assert.ok(
    body.indexOf("shouldRunIpFarmMutation") < body.indexOf("SELECT id, expression_json"),
    "debounce must happen before IP farm D1 scans"
  );
});

test("IP farm still runs asynchronously from request handling paths", () => {
  assert.match(index, /ctx\.waitUntil\(markForIpFarm/);
  assert.match(index, /ctx\.waitUntil\(triggerAIDefenseIfReady/);
  assert.match(challenge, /await addToIpFarm\(env, ip, `Challenge failure ban: \$\{reason\}`\)/);
});
