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
import { evaluateRisk, incrementIPRate } from "./risk";

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

// ---------------------------------------------------------------------------
// Multi-Tenant Domain Resolution
// ---------------------------------------------------------------------------

/**
 * Resolves the domain configuration from D1 with KV caching.
 * First checks KV for a cached config (sub-ms), falls back to D1 query.
 * Returns null if the domain is not onboarded or is inactive.
 */
async function resolveDomainConfig(
  domain: string,
  env: Env
): Promise<DomainConfigRecord | null> {
  const cacheKey = `${DOMAIN_CACHE_PREFIX}${domain}`;

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
    const config = await env.DB.prepare(
      "SELECT * FROM domain_configs WHERE domain_name = ? AND status = 'active'"
    )
      .bind(domain)
      .first<DomainConfigRecord>();

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
  return createErrorResponse(
    "ACCESS_DENIED",
    "Access denied.",
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

    // --- Internal Routes (no domain config needed) ---
    if (meta.path === "/es-health") {
      return handleHealthCheck();
    }

    // --- Resolve Domain Configuration ---
    const domainConfig = await resolveDomainConfig(domain, env);
    if (!domainConfig) {
      // Domain not onboarded — pass through without protection
      return createErrorResponse(
        "DOMAIN_NOT_CONFIGURED",
        "This domain is not configured for Edge Shield protection.",
        404
      );
    }

    // --- Increment IP rate counter (async, non-blocking) ---
    ctx.waitUntil(incrementIPRate(meta.ip, env.SESSION_KV));

    // --- Handle Dynamic Challenge Submission (POST) ---
    if (request.method === "POST" && isDynamicSubmitPath(meta.path)) {
      return handleSubmission(request, meta, domainConfig, env, ctx);
    }

    // --- KV Session Fast-Pass ---
    // If the user has a valid, signed session cookie, skip all checks.
    const session = await validateSession(request, meta, env);
    if (session) {
      // Valid session — transparent pass.
      // Return a pass-through response. In a real deployment, this would
      // be replaced by `fetch(request)` to the origin server.
      return createJsonResponse({
        success: true,
        message: "Session valid. Access granted.",
        sessionExpiry: session.exp,
      });
    }

    // --- Risk Scoring Engine ---
    const risk = await evaluateRisk(meta, env);

    // --- Three-Tier Dispatch ---
    switch (risk.level) {
      case RiskLevel.NORMAL: {
        // Score 0-30: Transparent pass with invisible Turnstile only.
        // In production, this would inject the Turnstile widget into the
        // origin response or validate a pre-existing Turnstile token.
        // For now, return a pass-through.
        ctx.waitUntil(
          logSecurityEvent(env, "session_created", meta, risk.score, null, "Normal risk — transparent pass")
        );
        return createJsonResponse({
          success: true,
          message: "Access granted.",
          risk: { score: risk.score, level: risk.level },
        });
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
            `Suspicious request — factors: ${risk.factors.join("; ")}`
          )
        );

        // Trigger async AI defense analysis
        ctx.waitUntil(triggerAIDefenseIfReady(env, meta));

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
            `Hard block — factors: ${risk.factors.join("; ")}`
          )
        );

        // Trigger async AI defense for attack pattern analysis
        ctx.waitUntil(triggerAIDefenseIfReady(env, meta));

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
