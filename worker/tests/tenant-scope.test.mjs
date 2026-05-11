import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const index = await readFile(new URL("../src/index.ts", import.meta.url), "utf8");
const risk = await readFile(new URL("../src/risk.ts", import.meta.url), "utf8");
const utils = await readFile(new URL("../src/utils.ts", import.meta.url), "utf8");

function functionBody(name) {
  const start = index.indexOf(`async function ${name}`);
  assert.notEqual(start, -1, `${name} should exist`);
  const next = index.indexOf("\nasync function ", start + 1);
  return index.slice(start, next === -1 ? undefined : next);
}

test("tenant scoped firewall rules are bound to the onboarded hostname tenant", () => {
  const body = functionBody("queryCustomFirewallRulesFromD1");

  assert.match(body, /domain_name IN \(\?, \?\)/);
  assert.match(body, /tenant_id = \? AND scope = 'tenant'/);
  assert.match(body, /bind\(normalizedDomain, apexDomain, tenant\)/);
  assert.doesNotMatch(body, /domain_name\s+LIKE/i);
});

test("tenant scoped protected paths are bound to the onboarded hostname tenant", () => {
  const body = functionBody("querySensitivePathsFromD1");

  assert.match(body, /domain_name IN \(\?, \?\)/);
  assert.match(body, /tenant_id = \? AND scope = 'tenant'/);
  assert.match(body, /bind\(normalizedDomain, apexDomain, tenant\)/);
  assert.doesNotMatch(body, /domain_name\s+LIKE/i);
});

test("runtime resolves tenant-wide scopes from the matched domain config tenant id", () => {
  assert.match(index, /queryDomainConfigFromD1\(normalizedDomain, env\)/);
  assert.match(index, /querySensitivePathsFromD1\(normalizedDomain, env, domainConfig\?\.tenant_id\)/);
  assert.match(index, /queryCustomFirewallRulesFromD1\(normalizedDomain, env, domainConfig\?\.tenant_id\)/);
});

test("custom firewall rules can match client.device_type", () => {
  assert.match(utils, /function detectDeviceType/);
  assert.match(utils, /Sec-CH-UA-Mobile/);
  assert.match(index, /field === "client\.device_type"/);
  assert.match(index, /actualValue = meta\.device_type\.toLowerCase\(\)/);
});

test("volatile rate and flood counters are domain scoped", () => {
  assert.match(risk, /rate:domainIP:\$\{domain\}:\$\{ip\}/);
  assert.match(risk, /rate:domainASN:\$\{domain\}:\$\{asn\}/);
  assert.match(risk, /rate:domainSubnet4:\$\{domain\}:\$\{subnet\}/);
  assert.match(risk, /rate:domainPath:\$\{domain\}:\$\{pathBucket\}/);
  assert.match(risk, /rate:domainASNPath:\$\{domain\}:\$\{asn\}:\$\{pathBucket\}/);
  assert.match(risk, /const composite = `\$\{domainKey\}:\$\{ip\}:\$\{uaHash\}`/);
  assert.match(index, /evaluateRisk\(meta, env, fingerprintHint, \{[\s\S]*domain,/);
});
