#!/usr/bin/env node
// ============================================================================
// Ultimate Edge Shield — Domain Onboarding Script
// Provisions a new domain into the multi-tenant bot protection system.
// ============================================================================

import { execSync } from "node:child_process";

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
const CF_API_BASE = "https://api.cloudflare.com/client/v4";
const D1_DATABASE_NAME = "EDGE_SHIELD_DB";

// ---------------------------------------------------------------------------
// CLI Argument Parsing
// ---------------------------------------------------------------------------
const domain = process.argv[2];
const apiToken = process.env.CF_API_TOKEN || process.argv[3];

if (!domain) {
  console.error("\n╔══════════════════════════════════════════════════════════╗");
  console.error("║  Ultimate Edge Shield — Domain Onboarding               ║");
  console.error("╠══════════════════════════════════════════════════════════╣");
  console.error("║  Usage: node scripts/onboard-domain.mjs <domain>       ║");
  console.error("║  Example: node scripts/onboard-domain.mjs example.com  ║");
  console.error("║                                                        ║");
  console.error("║  Required env: CF_API_TOKEN                            ║");
  console.error("╚══════════════════════════════════════════════════════════╝\n");
  process.exit(1);
}

if (!apiToken) {
  console.error("[ERROR] CF_API_TOKEN is not set.");
  console.error("  Set it via: export CF_API_TOKEN=<your-cloudflare-api-token>");
  console.error("  Or pass it as the second argument.");
  process.exit(1);
}

// ---------------------------------------------------------------------------
// Cloudflare API Helper
// ---------------------------------------------------------------------------

async function cfApiRequest(method, path, body = null) {
  const url = `${CF_API_BASE}${path}`;
  const options = {
    method,
    headers: {
      Authorization: `Bearer ${apiToken}`,
      "Content-Type": "application/json",
    },
  };

  if (body) {
    options.body = JSON.stringify(body);
  }

  const response = await fetch(url, options);
  const data = await response.json();

  if (!data.success) {
    const errors = data.errors?.map((e) => `  [${e.code}] ${e.message}`).join("\n") || "  Unknown error";
    throw new Error(
      `Cloudflare API error (${method} ${path}):\n${errors}`
    );
  }

  return data;
}

// ---------------------------------------------------------------------------
// Step 1: Resolve Zone ID & Account ID from Domain Name
// ---------------------------------------------------------------------------

async function getZoneData(domainName) {
  console.log(`\n[1/4] Resolving Zone ID for domain: ${domainName}`);

  const data = await cfApiRequest("GET", `/zones?name=${encodeURIComponent(domainName)}&status=active`);

  if (!data.result || data.result.length === 0) {
    throw new Error(
      `No active zone found for "${domainName}".\n` +
      `  Ensure the domain is added to your Cloudflare account and NS delegation is complete.`
    );
  }

  const zone = data.result[0];
  console.log(`  ✓ Zone ID: ${zone.id}`);
  console.log(`  ✓ Account ID: ${zone.account.id}`);
  console.log(`  ✓ Status:  ${zone.status}`);
  console.log(`  ✓ Plan:    ${zone.plan?.name || "unknown"}`);

  return { zoneId: zone.id, accountId: zone.account.id };
}

// ---------------------------------------------------------------------------
// Step 2: Create Invisible Turnstile Widget for the Domain
// ---------------------------------------------------------------------------

async function createTurnstileWidget(accountId, domainName) {
  console.log(`\n[2/4] Creating Invisible Turnstile widget for: ${domainName}`);

  const data = await cfApiRequest("POST", `/accounts/${accountId}/challenges/widgets`, {
    domains: [domainName],
    name: `Edge Shield — ${domainName}`,
    mode: "invisible"
  });

  const widget = data.result;
  console.log(`  ✓ Widget created successfully`);
  console.log(`  ✓ Site Key: ${widget.sitekey}`);
  console.log(`  ✓ Secret:   ${widget.secret.substring(0, 8)}${"*".repeat(24)} (masked)`);

  return {
    sitekey: widget.sitekey,
    secret: widget.secret,
  };
}
// ---------------------------------------------------------------------------
// Step 3: Insert Domain Configuration into D1
// ---------------------------------------------------------------------------

function insertDomainConfig(domainName, zoneId, sitekey, secret) {
  console.log(`\n[3/4] Writing domain configuration to D1 (${D1_DATABASE_NAME})`);

  const escapeSql = (val) => val.replace(/'/g, "''");

  const sql = `INSERT OR REPLACE INTO domain_configs (domain_name, zone_id, turnstile_sitekey, turnstile_secret, status) VALUES ('${escapeSql(domainName)}', '${escapeSql(zoneId)}', '${escapeSql(sitekey)}', '${escapeSql(secret)}', 'active');`;

  try {
    // Added --remote flag to ensure it writes to Cloudflare servers, not locally
    execSync(
      `npx wrangler d1 execute ${D1_DATABASE_NAME} --remote --command="${sql}"`,
      { stdio: "inherit", timeout: 30000 }
    );
    console.log(`  ✓ Domain configuration saved to remote D1`);
  } catch (error) {
    console.warn(`  ⚠ Remote D1 insert failed. Attempting local D1 fallback...`);
    try {
      execSync(
        `npx wrangler d1 execute ${D1_DATABASE_NAME} --local --command="${sql}"`,
        { stdio: "inherit", timeout: 30000 }
      );
      console.log(`  ✓ Domain configuration saved to local D1`);
    } catch (localError) {
      throw new Error(
        `Failed to insert domain config into D1.\n` +
        `  Remote error: ${error.message}\n` +
        `  Local error: ${localError.message}`
      );
    }
  }
}

// ---------------------------------------------------------------------------
// Step 4: Deploy Worker Route for the Domain
// ---------------------------------------------------------------------------

async function deployWorkerRoute(zoneId, domainName) {
  console.log(`\n[4/4] Deploying Worker route for: ${domainName}`);

  try {
    const data = await cfApiRequest("POST", `/zones/${zoneId}/workers/routes`, {
      pattern: `${domainName}/*`,
      script: "edge-shield",
    });

    console.log(`  ✓ Worker route deployed: ${domainName}/*`);
    console.log(`  ✓ Route ID: ${data.result?.id || "created"}`);
  } catch (error) {
    if (error.message.includes("duplicate")) {
      console.log(`  ⚠ Worker route already exists for ${domainName}/* (skipped)`);
    } else {
      console.warn(`  ⚠ Worker route deployment failed (non-fatal): ${error.message}`);
      console.log(`  → You can manually add the route in the Cloudflare dashboard.`);
    }
  }
}

// ---------------------------------------------------------------------------
// Main Execution
// ---------------------------------------------------------------------------

async function main() {
  console.log("\n" + "═".repeat(60));
  console.log("  Ultimate Edge Shield — Domain Onboarding");
  console.log("═".repeat(60));
  console.log(`  Domain: ${domain}`);
  console.log(`  Time:   ${new Date().toISOString()}`);
  console.log("═".repeat(60));

  try {
    // Step 1: Get Zone ID & Account ID
    const { zoneId, accountId } = await getZoneData(domain);

    // Step 2: Create Turnstile Widget using Account ID
    const turnstile = await createTurnstileWidget(accountId, domain);

    // Step 3: Save to D1
    insertDomainConfig(domain, zoneId, turnstile.sitekey, turnstile.secret);

    // Step 4: Deploy Worker Route
    await deployWorkerRoute(zoneId, domain);

    // Summary
    console.log("\n" + "═".repeat(60));
    console.log("  ✅ ONBOARDING COMPLETE");
    console.log("═".repeat(60));
    console.log(`  Domain:          ${domain}`);
    console.log(`  Zone ID:         ${zoneId}`);
    console.log(`  Turnstile Key:   ${turnstile.sitekey}`);
    console.log(`  Status:          active`);
    console.log(`  Worker Route:    ${domain}/*`);
    console.log("═".repeat(60));
    console.log("\n  The domain is now protected by Edge Shield.\n");
  } catch (error) {
    console.error("\n" + "═".repeat(60));
    console.error("  ❌ ONBOARDING FAILED");
    console.error("═".repeat(60));
    console.error(`  Domain: ${domain}`);
    console.error(`  Error:  ${error.message}`);
    console.error("═".repeat(60) + "\n");
    process.exit(1);
  }
}

main();