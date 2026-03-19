// ============================================================================
// Ultimate Edge Shield — Main Worker Entry Point
// The "Brain" of the system: fetch handler, routing, session validation,
// multi-tenant domain resolution, and 3-tier risk dispatch.
//
// Request Flow:
//   1. Extract request metadata + resolve domain config from D1
//   2. KV Session Fast-Pass: if valid signed session cookie → pass through
//   3. Risk Scoring Engine: evaluate request (0-100 score)
//   4. Three-tier dispatch:
//      - NORMAL  (0-30):   Invisible Turnstile only → pass
//      - SUSPICIOUS (31-70): Serve Slider CAPTCHA page
//      - MALICIOUS (71+):    Hard block at the edge
//   5. Async AI defense pipeline via ctx.waitUntil()
// ============================================================================

import type {
  Env,
  DomainConfigRecord,
  SessionTokenClaims,
  RequestMeta,
  RiskAssessment,
} from "./types";
import { RiskLevel } from "./types";
import { verifySessionToken } from "./crypto";
import {
  extractRequestMeta,
  getDomainFromRequest,
  createJsonResponse,
  createErrorResponse,
  createHtmlResponse,
} from "./utils";
import {
  evaluateRisk,
  incrementIPRate,
  incrementASNRate,
  incrementSubnetRate,
  getIPRateCount,
} from "./risk";

// Forward declarations — these modules will be created in Phases 4 and 5.
// We import them dynamically to avoid circular dependency issues during build.
// In Phase 4: import { handleChallengeGeneration, handleChallengeSubmission } from "./challenge";
// In Phase 5: import { triggerAIDefense } from "./ai-defense";

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Name of the session cookie set after successful challenge completion */
const SESSION_COOKIE_NAME = "es_session";

/** Session token validity duration (4 hours) */
const SESSION_TTL_SECONDS = 4 * 60 * 60;

/** KV key prefix for domain config caching */
const DOMAIN_CACHE_PREFIX = "dcfg:";

/** Domain config KV cache TTL (5 minutes) */
const DOMAIN_CACHE_TTL = 300;
const TEMP_BAN_PREFIX = "ban:ip:";
const TEMP_BAN_TTL_SECONDS = 10 * 60;
const IP_HARD_BAN_RATE_THRESHOLD = 120; // requests/minute per IP
const KNOWN_CRAWLER_UA_PATTERN =
  /(googlebot|google-inspectiontool|googleother|adsbot-google|mediapartners-google|bingbot|adidxbot|duckduckbot|yandexbot|baiduspider|applebot|slurp|amazonbot|facebookexternalhit|linkedinbot|twitterbot|petalbot|semrushbot|ahrefsbot|mj12bot|dotbot|seznambot|ccbot)/i;
type SecurityMode = "monitor" | "balanced" | "aggressive";
const AUTO_AGGR_PRESSURE_PREFIX = "mode:auto_aggr:pressure:";
const AUTO_AGGR_ACTIVE_PREFIX = "mode:auto_aggr:active:";
const AUTO_AGGR_PRESSURE_TTL_SECONDS = 3 * 60;
const AUTO_AGGR_ACTIVE_TTL_SECONDS = 10 * 60;
const AUTO_AGGR_TRIGGER_COUNT = 8;

// ---------------------------------------------------------------------------
// Multi-Tenant Domain Resolution
// ---------------------------------------------------------------------------

/**
 * Resolves the domain configuration from D1 with KV caching.
 * First checks KV for a cached config (sub-ms), falls back to D1 query.
 * Returns null if the domain is not onboarded.
 */
async function resolveDomainConfig(
  domain: string,
  env: Env
): Promise<DomainConfigRecord | null> {
  const normalizedDomain = domain.toLowerCase();
  const cacheKey = `${DOMAIN_CACHE_PREFIX}${normalizedDomain}`;

  // Fast path: check KV cache
  try {
    const cached = await env.SESSION_KV.get(cacheKey);
    if (cached) {
      return JSON.parse(cached) as DomainConfigRecord;
    }
  } catch {
    // KV read failure — fall through to D1
  }

  // Slow path: query D1
  try {
    let config = await env.DB.prepare(
      "SELECT * FROM domain_configs WHERE domain_name = ?"
    )
      .bind(normalizedDomain)
      .first<DomainConfigRecord>();

    // Fallback: allow www.<domain> host to use apex config entry.
    if (!config && normalizedDomain.startsWith("www.")) {
      const apexDomain = normalizedDomain.slice(4);
      config = await env.DB.prepare(
        "SELECT * FROM domain_configs WHERE domain_name = ?"
      )
        .bind(apexDomain)
        .first<DomainConfigRecord>();
    }

    if (config) {
      // Cache in KV for fast subsequent lookups
      try {
        await env.SESSION_KV.put(cacheKey, JSON.stringify(config), {
          expirationTtl: DOMAIN_CACHE_TTL,
        });
      } catch {
        // KV write failure is non-fatal
      }
    }

    return config;
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// Session Cookie Validation (KV Fast-Pass)
// ---------------------------------------------------------------------------

/**
 * Extracts and validates the Human Session Token from cookies.
 * Performs cryptographic verification + IP/fingerprint binding check.
 *
 * Returns the decoded claims if the session is valid, or null.
 * A valid session means the user has already passed the challenge.
 */
async function validateSession(
  request: Request,
  meta: RequestMeta,
  env: Env
): Promise<SessionTokenClaims | null> {
  // Extract session cookie
  const cookieHeader = request.headers.get("Cookie");
  if (!cookieHeader) return null;

  const sessionToken = parseCookieValue(cookieHeader, SESSION_COOKIE_NAME);
  if (!sessionToken) return null;

  // Verify JWT signature and expiration
  const claims = await verifySessionToken(sessionToken, env.JWT_SECRET);
  if (!claims) return null;

  // Verify IP binding — the token is bound to the original IP
  if (claims.ip !== meta.ip) return null;

  // Check if the session has been revoked in KV
  // (used when the AI defense engine bans an IP mid-session)
  try {
    const revoked = await env.SESSION_KV.get(`revoked:${claims.sub}`);
    if (revoked) return null;
  } catch {
    // KV failure — accept the session to avoid false blocks
  }

  return claims;
}

/**
 * Parses a specific cookie value from the Cookie header.
 * Handles standard cookie format: "name1=value1; name2=value2"
 */
function parseCookieValue(
  cookieHeader: string,
  name: string
): string | null {
  const prefix = `${name}=`;
  const cookies = cookieHeader.split(";");

  for (const cookie of cookies) {
    const trimmed = cookie.trim();
    if (trimmed.startsWith(prefix)) {
      return trimmed.substring(prefix.length);
    }
  }

  return null;
}

// ---------------------------------------------------------------------------
// Security Logging Helper
// ---------------------------------------------------------------------------

/**
 * Inserts a security event log into D1.
 * This is fire-and-forget — errors are silently swallowed to avoid
 * blocking the main request flow.
 */
async function logSecurityEvent(
  env: Env,
  eventType: string,
  meta: RequestMeta,
  riskScore: number | null,
  fingerprintHash: string | null,
  details: string | null
): Promise<void> {
  try {
    await env.DB.prepare(
      `INSERT INTO security_logs (event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`
    )
      .bind(
        eventType,
        meta.ip,
        meta.asn,
        meta.country,
        meta.path,
        fingerprintHash,
        riskScore,
        details
      )
      .run();
  } catch {
    // Logging failure must never crash the Worker
  }
}

/**
 * Checks whether an IP is temporarily banned in KV.
 * Returns true when ban marker is present.
 */
async function isTemporarilyBanned(ip: string, env: Env): Promise<boolean> {
  try {
    const ban = await env.SESSION_KV.get(`${TEMP_BAN_PREFIX}${ip}`);
    return ban === "1";
  } catch {
    return false;
  }
}

function isAllowedCrawler(meta: RequestMeta): boolean {
  if (!/^(GET|HEAD)$/i.test(meta.method)) return false;
  // Strong path: Cloudflare-verified good bot.
  if (meta.verifiedBot) return true;
  // Compatibility path for plans where bot verification isn't exposed in request.cf.
  return KNOWN_CRAWLER_UA_PATTERN.test(meta.userAgent);
}

function getSecurityMode(domainConfig: DomainConfigRecord): SecurityMode {
  const mode = String(domainConfig.security_mode || "balanced").toLowerCase();
  if (mode === "monitor" || mode === "aggressive" || mode === "balanced") {
    return mode;
  }
  return "balanced";
}

function mapRiskByMode(
  score: number,
  mode: SecurityMode
): RiskLevel {
  if (mode === "monitor") {
    // Monitor mode is conservative: no automatic hard-block by risk score.
    if (score > 35) return RiskLevel.SUSPICIOUS;
    return RiskLevel.NORMAL;
  }

  if (mode === "aggressive") {
    if (score > 55) return RiskLevel.MALICIOUS;
    if (score > 20) return RiskLevel.SUSPICIOUS;
    return RiskLevel.NORMAL;
  }

  // Balanced (default)
  if (score > 70) return RiskLevel.MALICIOUS;
  if (score > 30) return RiskLevel.SUSPICIOUS;
  return RiskLevel.NORMAL;
}

function hasAttackPressureSignal(risk: RiskAssessment): boolean {
  if (risk.score >= 65) return true;
  const factors = risk.factors.join(" ").toLowerCase();
  return (
    factors.includes("burst") ||
    factors.includes("high request rate") ||
    factors.includes("elevated request rate") ||
    factors.includes("asn traffic") ||
    factors.includes("datacenter/hosting asn")
  );
}

function shouldTriggerAIDefense(
  risk: RiskAssessment,
  mode: SecurityMode
): boolean {
  if (risk.score >= 75) return true;

  const factors = risk.factors.join(" ").toLowerCase();
  const hasBurst = factors.includes("burst") || factors.includes("asn traffic");
  if (hasBurst && risk.score >= 45) return true;

  if (mode === "aggressive" && risk.score >= 50) return true;

  return false;
}

async function resolveEffectiveSecurityMode(
  domainConfig: DomainConfigRecord,
  risk: RiskAssessment,
  meta: RequestMeta,
  env: Env,
  ctx: ExecutionContext
): Promise<SecurityMode> {
  const configured = getSecurityMode(domainConfig);
  if (configured !== "balanced") return configured;

  const domain = String(domainConfig.domain_name || "").toLowerCase();
  if (!domain) return configured;

  const activeKey = `${AUTO_AGGR_ACTIVE_PREFIX}${domain}`;
  const pressureKey = `${AUTO_AGGR_PRESSURE_PREFIX}${domain}`;

  try {
    const active = await env.SESSION_KV.get(activeKey);
    if (active === "1") return "aggressive";

    if (!hasAttackPressureSignal(risk)) return configured;

    const current = parseInt((await env.SESSION_KV.get(pressureKey)) || "0", 10) || 0;
    const next = current + 1;
    await env.SESSION_KV.put(pressureKey, String(next), {
      expirationTtl: AUTO_AGGR_PRESSURE_TTL_SECONDS,
    });

    if (next >= AUTO_AGGR_TRIGGER_COUNT) {
      await env.SESSION_KV.put(activeKey, "1", {
        expirationTtl: AUTO_AGGR_ACTIVE_TTL_SECONDS,
      });
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "mode_escalated",
          meta,
          risk.score,
          null,
          `Auto-escalated domain to aggressive for ${AUTO_AGGR_ACTIVE_TTL_SECONDS}s (pressure=${next})`
        )
      );
      return "aggressive";
    }
  } catch {
    // KV failures are non-fatal; remain in configured mode.
  }

  return configured;
}

// ---------------------------------------------------------------------------
// Route Handlers
// ---------------------------------------------------------------------------

/**
 * Handles the internal health check endpoint.
 * Returns basic Worker status information.
 */
function handleHealthCheck(): Response {
  return createJsonResponse({
    status: "operational",
    service: "edge-shield",
    timestamp: Date.now(),
  });
}

/**
 * Serves the CAPTCHA challenge page for suspicious requests.
 * The challenge HTML includes the Turnstile widget and slider CAPTCHA.
 * The dynamic submit path is signed per-session using the nonce.
 */
async function serveChallengePagePlaceholder(
  meta: RequestMeta,
  domainConfig: DomainConfigRecord,
  env: Env
): Promise<Response> {
  // Phase 4 will implement the full challenge generation.
  // For now, we generate a placeholder that will be replaced.
  // The actual implementation will:
  //   1. Generate a random target_x and nonce
  //   2. Store the challenge in D1
  //   3. Sign a dynamic submit path
  //   4. Serve the HTML with Turnstile + Slider

  // Dynamic import of challenge module (available after Phase 4)
  try {
    const { handleChallengeGeneration } = await import("./challenge");
    return handleChallengeGeneration(meta, domainConfig, env);
  } catch {
    // Phase 4 not yet implemented — return a basic challenge page
    return createHtmlResponse(
      `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Security Verification</title>
</head>
<body>
  <h1>Security Verification Required</h1>
  <p>Please complete the verification to continue.</p>
  <p><em>Challenge system initializing... (Phase 4 pending)</em></p>
</body>
</html>`,
      403
    );
  }
}

/**
 * Handles challenge submission (POST from the slider CAPTCHA).
 * Routes to the challenge module for telemetry validation.
 */
async function handleSubmission(
  request: Request,
  meta: RequestMeta,
  domainConfig: DomainConfigRecord,
  env: Env,
  ctx: ExecutionContext
): Promise<Response> {
  try {
    const { handleChallengeSubmission } = await import("./challenge");
    return handleChallengeSubmission(request, meta, domainConfig, env, ctx);
  } catch {
    return createErrorResponse(
      "CHALLENGE_UNAVAILABLE",
      "Challenge system is not available",
      503
    );
  }
}

/**
 * Returns a hard block response for malicious requests.
 * Minimal information is disclosed to the attacker.
 */
function handleHardBlock(): Response {
  return createHtmlResponse(
    `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Access Denied</title>
  <style>
    body { margin:0; font-family: Arial, Helvetica, sans-serif; background:#f5f7fb; color:#111827; }
    .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .card { width:100%; max-width:480px; background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; box-shadow:0 12px 30px rgba(15,23,42,.08); text-align:center; }
    .badge { display:inline-block; padding:6px 10px; border-radius:999px; background:#fee2e2; color:#991b1b; font-size:12px; font-weight:700; margin-bottom:10px; }
    h1 { margin:0 0 8px; font-size:22px; }
    p { margin:0; color:#4b5563; line-height:1.6; font-size:14px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="badge">Security Protection</div>
      <h1>Access denied</h1>
      <p>Your request was blocked by the security policy.</p>
    </div>
  </div>
</body>
</html>`,
    403
  );
}

// ---------------------------------------------------------------------------
// Dynamic Submit Path Validation
// ---------------------------------------------------------------------------

/**
 * Checks if a POST request path matches the dynamic challenge submission format.
 * Dynamic paths follow the pattern: /es-verify/<nonce-prefix>
 * This prevents attackers from using a single static endpoint.
 */
function isDynamicSubmitPath(path: string): boolean {
  return /^\/es-verify\/[a-f0-9]{16,}$/.test(path);
}

// ---------------------------------------------------------------------------
// Main Fetch Handler
// ---------------------------------------------------------------------------

const worker: ExportedHandler<Env> = {
  async fetch(
    request: Request,
    env: Env,
    ctx: ExecutionContext
  ): Promise<Response> {
    const meta = extractRequestMeta(request);
    const domain = getDomainFromRequest(request);

    // Allow-list crawlers for indexing (including Google/Amazon/Bing and peers).
    if (isAllowedCrawler(meta)) {
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "session_created",
          meta,
          0,
          null,
          meta.verifiedBot
            ? "Verified crawler allow-listed"
            : "Known crawler UA allow-listed (compat mode)"
        )
      );
      return fetch(request);
    }

    // Fast hard-stop for known abusive IPs (temporary ban window).
    if (await isTemporarilyBanned(meta.ip, env)) {
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "hard_block",
          meta,
          100,
          null,
          `Temporarily banned IP (${TEMP_BAN_TTL_SECONDS}s window)`
        )
      );
      return handleHardBlock();
    }

    // --- Internal Routes (no domain config needed) ---
    if (meta.path === "/es-health") {
      return handleHealthCheck();
    }

    // --- Resolve Domain Configuration ---
    // In test mode, we might not have a domain config, so we resolve it here but don't strictly require it
    // yet. We'll check again after the test mode block.
    const domainConfig = await resolveDomainConfig(domain, env);

    // --- Test Mode (for development/staging verification) ---
    // Activate with: ?__es_test=challenge | block | risk
    // Requires header: X-ES-Test-Key matching first 16 chars of JWT_SECRET
    // Runs before domain validation so it can be tested directly on *.workers.dev
    const testMode = new URL(request.url).searchParams.get("__es_test");
    if (testMode && env.ES_TEST_MODE === "on") {
      const testKey = request.headers.get("X-ES-Test-Key");
      const jwtSecret: string | undefined = env.JWT_SECRET;
      const expectedKey = jwtSecret ? jwtSecret.substring(0, 16) : null;

      if (testKey && expectedKey && testKey === expectedKey) {
        if (testMode === "challenge") {
          // Provide a dummy domain config if tested outside an onboarded domain
          const dummyConfig = domainConfig || {
            domain_name: domain,
            zone_id: "test_zone",
            turnstile_sitekey: "1x00000000000000000000AA", // Cloudflare test key (always passes)
            turnstile_secret: "1x0000000000000000000000000000000AA", // Cloudflare test secret
            force_captcha: 0,
            security_mode: "balanced",
            status: "active",
            created_at: new Date().toISOString(),
          };
          return serveChallengePagePlaceholder(meta, dummyConfig, env);
        }
        if (testMode === "block") {
          return handleHardBlock();
        }
        if (testMode === "risk") {
          const risk = await evaluateRisk(meta, env);
          return createJsonResponse({
            testMode: true,
            risk,
            meta: {
              ip: meta.ip,
              asn: meta.asn,
              country: meta.country,
              userAgent: meta.userAgent,
              tlsVersion: meta.tlsVersion,
              botManagementScore: meta.botManagementScore,
            },
          });
        }
      }
    }

    if (!domainConfig) {
      // Domain not onboarded — transparently pass through.
      return fetch(request);
    }

    if (domainConfig.status !== "active") {
      // Paused/revoked domains should bypass protection transparently.
      return fetch(request);
    }

    // --- Increment IP rate counter (async, non-blocking) ---
    ctx.waitUntil(incrementIPRate(meta.ip, env.SESSION_KV));
    ctx.waitUntil(incrementSubnetRate(meta.ip, env.SESSION_KV));
    if (meta.asn) {
      ctx.waitUntil(incrementASNRate(meta.asn, env.SESSION_KV));
    }
    // Immediate ban for IPs that exceed the allowed requests/minute window.
    // This is intentionally aggressive for anti-abuse hardening.
    try {
      const ipRate = await getIPRateCount(meta.ip, env.SESSION_KV);
      if (ipRate >= IP_HARD_BAN_RATE_THRESHOLD) {
        await env.SESSION_KV.put(`${TEMP_BAN_PREFIX}${meta.ip}`, "1", {
          expirationTtl: TEMP_BAN_TTL_SECONDS,
        });
        ctx.waitUntil(
          logSecurityEvent(
            env,
            "hard_block",
            meta,
            100,
            null,
            `Auto-banned by IP rate policy (${ipRate}/min >= ${IP_HARD_BAN_RATE_THRESHOLD}/min)`
          )
        );
        return handleHardBlock();
      }
    } catch {
      // KV errors are non-fatal; continue normal flow
    }

    // --- Handle Dynamic Challenge Submission (POST) ---
    if (request.method === "POST" && isDynamicSubmitPath(meta.path)) {
      return handleSubmission(request, meta, domainConfig, env, ctx);
    }

    // --- KV Session Fast-Pass ---
    // If the user has a valid, signed session cookie, skip all checks.
    const session = await validateSession(request, meta, env);
    if (session) {
      // Valid session — transparent pass.
      // Pass the request to the origin server.
      return fetch(request);
    }

    // --- Forced CAPTCHA Mode (per-domain) ---
    // If enabled in domain config, every request must pass challenge first.
    if (Number(domainConfig.force_captcha ?? 0) === 1) {
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "challenge_issued",
          meta,
          65,
          null,
          "Forced CAPTCHA mode enabled for this domain"
        )
      );
      return serveChallengePagePlaceholder(meta, domainConfig, env);
    }

    // --- Risk Scoring Engine ---
    const risk = await evaluateRisk(meta, env);
    const securityMode = await resolveEffectiveSecurityMode(
      domainConfig,
      risk,
      meta,
      env,
      ctx
    );
    const effectiveRiskLevel = mapRiskByMode(risk.score, securityMode);

    // --- Three-Tier Dispatch ---
    switch (effectiveRiskLevel) {
      case RiskLevel.NORMAL: {
        // Score 0-30: Transparent pass with invisible Turnstile only.
        // In production, this would inject the Turnstile widget into the
        // origin response or validate a pre-existing Turnstile token.
        // For now, pass the request to the origin server.
        ctx.waitUntil(
          logSecurityEvent(
            env,
            "session_created",
            meta,
            risk.score,
            null,
            `Normal risk — transparent pass (mode=${securityMode})`
          )
        );
        return fetch(request);
      }

      case RiskLevel.SUSPICIOUS: {
        // Score 31-70: Escalate to Slider CAPTCHA + Turnstile
        ctx.waitUntil(
          logSecurityEvent(
            env,
            "challenge_issued",
            meta,
            risk.score,
            null,
            `Suspicious request (mode=${securityMode}) — factors: ${risk.factors.join("; ")}`
          )
        );

        // Trigger async AI defense only on stronger attack signals.
        if (shouldTriggerAIDefense(risk, securityMode)) {
          ctx.waitUntil(triggerAIDefenseIfReady(env, meta));
        }

        return serveChallengePagePlaceholder(meta, domainConfig, env);
      }

      case RiskLevel.MALICIOUS: {
        // Score 71+: Hard block at the edge
        ctx.waitUntil(
          logSecurityEvent(
            env,
            "hard_block",
            meta,
            risk.score,
            null,
            `Hard block (mode=${securityMode}) — factors: ${risk.factors.join("; ")}`
          )
        );

        // Trigger async AI defense only when attack indicators are strong.
        if (shouldTriggerAIDefense(risk, securityMode)) {
          ctx.waitUntil(triggerAIDefenseIfReady(env, meta));
        }

        return handleHardBlock();
      }
    }
  },
};

// ---------------------------------------------------------------------------
// Async AI Defense Trigger
// ---------------------------------------------------------------------------

/**
 * Attempts to trigger the AI defense pipeline.
 * Gracefully handles the case where Phase 5 is not yet implemented.
 * This function is called via ctx.waitUntil() and runs asynchronously
 * after the response has been sent to the client.
 */
async function triggerAIDefenseIfReady(
  env: Env,
  meta: RequestMeta
): Promise<void> {
  try {
    const { triggerAIDefense } = await import("./ai-defense");
    await triggerAIDefense(env, meta);
  } catch {
    // Phase 5 not yet implemented — silently skip
  }
}

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export default worker;
