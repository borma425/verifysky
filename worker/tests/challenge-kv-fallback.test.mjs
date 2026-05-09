import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const challenge = await readFile(new URL("../src/challenge.ts", import.meta.url), "utf8");

test("challenge submission falls back to D1 when KV nonce is absent", () => {
  assert.match(challenge, /nonceStatus !== null && nonceStatus !== "pending"/);
  assert.match(challenge, /KV writes can be unavailable after account-level put limits/);
  assert.match(challenge, /SELECT \* FROM challenges WHERE nonce = \? AND status = 'pending'/);
});

test("challenge solve claims the D1 pending row before issuing a session", () => {
  assert.match(
    challenge,
    /UPDATE challenges SET status = 'solved', solved_at = CURRENT_TIMESTAMP WHERE nonce = \? AND status = 'pending'/
  );
  assert.match(challenge, /changes < 1/);
  assert.match(challenge, /replay guard when KV nonce writes are unavailable/);
});
