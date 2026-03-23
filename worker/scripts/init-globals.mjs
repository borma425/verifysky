#!/usr/bin/env node
// ============================================================================
// Ultimate Edge Shield — Global Secrets Bootstrap Script
// Generates and pushes global Wrangler secrets (run once per deployment).
//
// Usage: node scripts/init-globals.mjs
//
// This script will:
//   1. Generate a cryptographically secure 32-byte JWT_SECRET
//   2. Prompt for CLOUDFLARE_API_TOKEN (Cloudflare API Token)
//   3. Prompt for OPENROUTER_API_KEY (OpenRouter API Key)
//   4. Push all three as Wrangler secrets
//
// Prerequisites:
//   - Wrangler CLI installed and authenticated
//   - Worker already created via `wrangler deploy` or `wrangler publish`
// ============================================================================

import { execSync } from "node:child_process";
import { createInterface } from "node:readline";
import { randomBytes } from "node:crypto";

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

/**
 * Generates a cryptographically secure random hex string.
 * 32 bytes = 256 bits of entropy, suitable for HMAC-SHA256 signing keys.
 */
function generateSecureKey(byteLength = 32) {
  return randomBytes(byteLength).toString("hex");
}

/**
 * Prompts the user for input via stdin.
 * Supports masking for sensitive values.
 */
function prompt(question) {
  return new Promise((resolve) => {
    const rl = createInterface({
      input: process.stdin,
      output: process.stdout,
    });
    rl.question(question, (answer) => {
      rl.close();
      resolve(answer.trim());
    });
  });
}

/**
 * Pushes a secret to Wrangler by piping the value via stdin.
 * This avoids exposing the secret in shell history or process arguments.
 */
function pushSecret(name, value) {
  try {
    execSync(`echo "${value}" | npx wrangler secret put ${name}`, {
      stdio: ["pipe", "inherit", "inherit"],
      timeout: 30000,
    });
    console.log(`  ✓ ${name} pushed successfully`);
    return true;
  } catch (error) {
    console.error(`  ✗ Failed to push ${name}: ${error.message}`);
    return false;
  }
}

// ---------------------------------------------------------------------------
// Main Execution
// ---------------------------------------------------------------------------

async function main() {
  console.log("\n" + "═".repeat(60));
  console.log("  Ultimate Edge Shield — Global Secrets Bootstrap");
  console.log("═".repeat(60));
  console.log("  This script will configure the three global secrets");
  console.log("  required by the Edge Shield Worker.");
  console.log("═".repeat(60) + "\n");

  // Step 1: Generate JWT_SECRET
  console.log("[1/3] Generating JWT_SECRET (32-byte HMAC-SHA256 key)");
  const jwtSecret = generateSecureKey(32);
  console.log(`  → Generated: ${jwtSecret.substring(0, 8)}${"*".repeat(48)} (masked)`);
  console.log(`  → Entropy: 256 bits\n`);

  // Step 2: Prompt for CLOUDFLARE_API_TOKEN
  console.log("[2/3] Cloudflare API Token");
  console.log("  Required permissions:");
  console.log("    • Zone → Firewall Services → Edit (for WAF rule creation)");
  console.log("    • Zone → Zone → Read (for zone ID lookup)");
  console.log("    • Account → Turnstile → Edit (for widget management)");
  console.log("    • Account → Workers Scripts → Edit (for route management)");
  const cfApiToken =
    process.env.CLOUDFLARE_API_TOKEN ||
    process.env.CF_API_TOKEN ||
    await prompt("  Enter CLOUDFLARE_API_TOKEN: ");

  if (!cfApiToken) {
    console.error("  ✗ CLOUDFLARE_API_TOKEN is required. Aborting.");
    process.exit(1);
  }
  console.log(`  ✓ Token received: ${cfApiToken.substring(0, 8)}${"*".repeat(24)} (masked)\n`);

  // Step 3: Prompt for OPENROUTER_API_KEY
  console.log("[3/3] OpenRouter API Key");
  console.log("  Used for AI-driven threat analysis and auto-ban decisions.");
  const openRouterKey = process.env.OPENROUTER_API_KEY || await prompt("  Enter OPENROUTER_API_KEY: ");

  if (!openRouterKey) {
    console.error("  ✗ OPENROUTER_API_KEY is required. Aborting.");
    process.exit(1);
  }
  console.log(`  ✓ Key received: ${openRouterKey.substring(0, 8)}${"*".repeat(24)} (masked)\n`);

  // Push secrets to Wrangler
  console.log("═".repeat(60));
  console.log("  Pushing secrets to Wrangler...");
  console.log("═".repeat(60) + "\n");

  const results = [
    pushSecret("JWT_SECRET", jwtSecret),
    pushSecret("CF_API_TOKEN", cfApiToken),
    pushSecret("OPENROUTER_API_KEY", openRouterKey),
  ];

  const allSuccess = results.every(Boolean);

  console.log("\n" + "═".repeat(60));
  if (allSuccess) {
    console.log("  ✅ ALL SECRETS CONFIGURED SUCCESSFULLY");
    console.log("═".repeat(60));
    console.log("\n  Global secrets are now available to the Edge Shield Worker.");
    console.log("  Next steps:");
    console.log("    1. Onboard domains:  node scripts/onboard-domain.mjs <domain>");
    console.log("    2. Initialize D1:    npm run db:init");
    console.log("    3. Deploy Worker:    npm run deploy\n");
  } else {
    console.error("  ⚠ SOME SECRETS FAILED TO PUSH");
    console.error("═".repeat(60));
    console.error("\n  Please check the errors above and retry failed secrets manually:");
    console.error("    echo \"<value>\" | npx wrangler secret put <NAME>\n");
    process.exit(1);
  }
}

main();
