import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const challenge = await readFile(new URL("../src/challenge.ts", import.meta.url), "utf8");

function generationBody() {
  const start = challenge.indexOf("export async function handleChallengeGeneration");
  const end = challenge.indexOf("// Challenge Submission & Validation");
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  return challenge.slice(start, end);
}

test("challenge issuance is stateless and performs no D1 or KV writes", () => {
  const body = generationBody();

  assert.match(body, /createStatelessChallengeNonce/);
  assert.doesNotMatch(body, /env\.DB\.prepare/);
  assert.doesNotMatch(body, /INSERT INTO challenges/);
  assert.doesNotMatch(body, /SESSION_KV\.put/);
});

test("stateless challenge nonce carries expiry and derives target from HMAC", () => {
  assert.match(challenge, /STATELESS_CHALLENGE_VERSION/);
  assert.match(challenge, /statelessChallengePayload/);
  assert.match(challenge, /statelessChallengeMacPayload/);
  assert.match(challenge, /timeSafeEqual/);
  assert.match(challenge, /target_x:\s*targetX/);
  assert.match(challenge, /expiresAtSeconds \* 1000 < Date\.now\(\)/);
});

test("legacy D1 challenge rows remain supported only as fallback", () => {
  assert.match(challenge, /const isStatelessChallenge = statelessChallenge\?\.status === "valid"/);
  assert.match(challenge, /if \(isStatelessChallenge\) {\s*challenge = statelessChallenge\.challenge;\s*} else {/s);
  assert.match(challenge, /SELECT \* FROM challenges WHERE nonce = \? AND status = 'pending'/);
  assert.match(challenge, /if \(!isStatelessChallenge\) {\s*try {\s*const solved = await env\.DB\.prepare/s);
});
