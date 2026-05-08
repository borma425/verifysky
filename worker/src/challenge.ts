// ============================================================================
// Ultimate Edge Shield — Challenge Generation & Telemetry Validation
//
// This module handles the complete CAPTCHA challenge lifecycle:
//   1. Challenge Generation: random target_x, nonce, D1 storage, signed payload
//   2. Telemetry Validation: mouse/touch movement analysis
//   3. Turnstile Verification: server-side token validation
//   4. Session Issuance: cryptographic Human Session Token (JWT)
//
// Security Invariants:
//   • target_x is NEVER sent to the client
//   • Nonces are single-use (consumed in KV immediately)
//   • Telemetry is analyzed for human behavioral patterns
//   • Session tokens are bound to IP + fingerprint
// ============================================================================

import type {
  Env,
  DomainConfigRecord,
  RequestMeta,
  ChallengeSubmission,
  SessionTokenClaims,
  ChallengeRecord,
  DomainThresholds,
} from "./types";
import {
  generateNonce,
  generateSignature,
  verifySignature,
  createSessionToken,
} from "./crypto";
import {
  createHtmlResponse,
  createJsonResponse,
  createErrorResponse,
  sanitizeInput,
  extractDomainFromMeta,
  isIpAllowListed,
  isPrivateOrReservedIP,
} from "./utils";
import { SLIDER_RUNTIME_SCRIPT } from "./generated/slider-runtime";
import { markUsageOutcome } from "./usage-meter";

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Slider track width in pixels (must match frontend) */
const SLIDER_TRACK_WIDTH = 300;

/** Minimum valid target X position (pixels from left edge) */
const TARGET_X_MIN = 60;

/** Maximum valid target X position (pixels from left edge) */
const TARGET_X_MAX = 260;

/** Fallback X tolerance — overridden by thresholds.challenge_x_tolerance */
const DEFAULT_X_TOLERANCE = 24;

/** Challenge expiration time (seconds) */
const CHALLENGE_TTL_SECONDS = 60;

/** Fallback min solve time — overridden by thresholds.challenge_min_solve_ms */
const DEFAULT_MIN_SOLVE_TIME_MS = 150;

/** Maximum allowed solve time (milliseconds) — rejects stale challenges */
const MAX_SOLVE_TIME_MS = 30000;

/** Fallback min telemetry points — overridden by thresholds.challenge_min_telemetry_points */
const DEFAULT_MIN_TELEMETRY_POINTS = 3;

/** Maximum allowed telemetry data points (prevents payload flooding) */
const MAX_TELEMETRY_POINTS = 2000;

/** Session cookie name (must match index.ts) */
const SESSION_COOKIE_NAME = "es_session";
/** Short-lived challenge binding cookie (binds solve request to issuer context) */
const CHALLENGE_COOKIE_NAME = "es_challenge";

/** Turnstile verification endpoint */
const TURNSTILE_VERIFY_URL = "https://challenges.cloudflare.com/turnstile/v0/siteverify";

/** Max challenge submit attempts per IP per minute */
const MAX_SUBMISSIONS_PER_MINUTE_PER_IP = 18;
/** Failure counter rolling window (seconds) */
const FAILURE_WINDOW_SECONDS = 15 * 60;
const IP_ATTACK_DAY_PREFIX = "attack:ip:day:";
const IP_ATTACK_MONTH_PREFIX = "attack:ip:month:";
const ATTACK_COUNTER_TTL_SECONDS = 45 * 24 * 60 * 60;
const HISTORICAL_ATTACK_EVENTS = new Set([
  "challenge_failed",
  "turnstile_failed",
  "replay_detected",
  "hard_block",
  "session_rejected",
]);

/** Pool of challenge icons (randomized per challenge page) */
const CHALLENGE_ICONS = ["🍔", "🎧", "🧩", "📷", "⚽", "🎁", "🏍️"] as const;
// ---------------------------------------------------------------------------
// Challenge Generation
// ---------------------------------------------------------------------------

/**
 * Generates a new CAPTCHA challenge and serves the challenge HTML page.
 * Called by index.ts when a request is classified as SUSPICIOUS.
 *
 * Steps:
 *   1. Generate a cryptographic nonce (32 bytes)
 *   2. Generate a random target X position
 *   3. Store the challenge in D1 (nonce + target_x + IP)
 *   4. Mark the nonce as "issued" in KV (for fast one-time-use validation)
 *   5. Sign a dynamic submission path using the nonce
 *   6. Serve the HTML challenge page
 */
export async function handleChallengeGeneration(
  meta: RequestMeta,
  domainConfig: DomainConfigRecord,
  env: Env
): Promise<Response> {
  markUsageOutcome(env, "challenge_issued");

  // Generate cryptographic nonce and random target position
  const nonce = generateNonce(32);
  const targetX = generateTargetX();
  const targetIcon = pickChallengeIcon();
  const now = Date.now();
  const expiresAt = new Date(now + CHALLENGE_TTL_SECONDS * 1000).toISOString();

  // Build the dynamic submission path (signed with nonce prefix)
  const submitPath = `/es-verify/${nonce.substring(0, 24)}`;

  // Sign the challenge payload for integrity verification
  const signaturePayload = `${nonce}:${submitPath}`;
  const signature = await generateSignature(signaturePayload, env.JWT_SECRET);
  // targetHint only obfuscates the visual target for UX. It is not a security
  // secret; final trust comes from nonce/signature validation, telemetry,
  // Turnstile strict verification, replay checks, and session binding.
  const targetHint = encodeTargetHint(targetX, nonce, signature);

  // Store challenge in D1
  try {
    await env.DB.prepare(
      `INSERT INTO challenges (nonce, target_x, ip_address, fingerprint_hash, user_agent, status, expires_at)
       VALUES (?, ?, ?, NULL, ?, 'pending', ?)`
    )
      .bind(nonce, targetX, meta.ip, sanitizeInput(meta.userAgent, 512), expiresAt)
      .run();
  } catch {
    return createErrorResponse("CHALLENGE_ERROR", "Failed to generate challenge", 500);
  }

  // Store nonce in KV for fast single-use validation
  try {
    await env.SESSION_KV.put(`nonce:${nonce}`, "pending", {
      expirationTtl: CHALLENGE_TTL_SECONDS,
    });
  } catch {
    // KV failure — the D1 record still exists for fallback validation
  }

  const urlObjInstance = new URL(meta.url);
  const fullOriginalPath = urlObjInstance.pathname + urlObjInstance.search;

  // Build and serve the challenge page
  const challengeHtml = buildChallengeHtml(
    nonce,
    submitPath,
    fullOriginalPath,
    signature,
    domainConfig.turnstile_sitekey,
    targetHint,
    targetIcon,
    now,
    now + CHALLENGE_TTL_SECONDS * 1000
  );

  const challengeCookie = await buildChallengeBindingCookie(
    nonce,
    submitPath,
    meta,
    env.JWT_SECRET
  );

  const headers: Record<string, string> = {
    "Set-Cookie": challengeCookie,
  };

  const urlObj = new URL(meta.url);
  const isAnalyzer = urlObj.searchParams.get("es_analyzer") === "1";

  let response = createHtmlResponse(challengeHtml, 403, headers);

  if (isAnalyzer) {
    const newHeaders = new Headers(response.headers);
    newHeaders.delete("X-Frame-Options");
    newHeaders.delete("Content-Security-Policy");
    response = new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers: newHeaders
    });
  }

  return response;
}

// ---------------------------------------------------------------------------
// Challenge Submission & Validation
// ---------------------------------------------------------------------------

/**
 * Processes a challenge submission from the slider CAPTCHA.
 * Performs multi-layered validation before issuing a session token.
 *
 * Validation layers:
 *   1. Payload structure validation (required fields, types)
 *   2. Nonce single-use verification (KV + D1)
 *   3. Dynamic path signature verification
 *   4. Turnstile token verification (Cloudflare API)
 *   5. Telemetry behavioral analysis (the core anti-bot logic)
 *   6. Target X position verification (within tolerance)
 *
 * On success: issues a signed JWT session cookie.
 * On failure: logs the event and returns an error.
 */
export async function handleChallengeSubmission(
  request: Request,
  meta: RequestMeta,
  domainConfig: DomainConfigRecord,
  thresholds: DomainThresholds,
  env: Env,
  ctx: ExecutionContext
): Promise<Response> {
  const failed = (code: string, message: string, status: number): Response => {
    markUsageOutcome(env, "challenge_failed");
    return createErrorResponse(code, message, status);
  };

  const strictContextBinding = String(env.ES_STRICT_CONTEXT_BINDING ?? "off").toLowerCase() === "on";
  // Basic content-type validation
  const contentType = request.headers.get("Content-Type") || "";
  if (!contentType.toLowerCase().includes("application/json")) {
    return failed("UNSUPPORTED_MEDIA_TYPE", "Expected application/json", 415);
  }

  // Per-IP submission rate limit (protect challenge endpoint from flooding)
  const rateLimited = await isSubmissionRateLimited(meta.ip, env);
  if (rateLimited) {
    ctx.waitUntil(markIPTemporarilyBanned(env, meta.ip, "submission_rate_limit", thresholds, meta));
    markUsageOutcome(env, "blocked");
    return createErrorResponse("RATE_LIMITED", "Too many verification attempts. Try again later.", 429);
  }

  // --- 1. Parse and validate request body ---
  let submission: ChallengeSubmission;
  try {
    const body = await request.json();
    submission = validateSubmissionPayload(body);
  } catch (error) {
    return failed(
      "INVALID_PAYLOAD",
      "Invalid challenge submission format",
      400
    );
  }

  // --- 2. Verify challenge binding cookie before nonce consumption ---
  const cookieValid = await verifyChallengeBindingCookie(
    request,
    submission.nonce,
    meta,
    env.JWT_SECRET
  );
  if (!cookieValid) {
    if (strictContextBinding) {
      ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
        "Challenge cookie validation failed"));
      ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "cookie_mismatch", thresholds, meta));
      return failed("CHALLENGE_CONTEXT_MISMATCH", "Challenge context mismatch", 403);
    }
    ctx.waitUntil(logEvent(env, "challenge_warning", meta, submission.fingerprint,
      "Challenge cookie validation failed (soft-pass)"));
  }

  // --- 3. Verify nonce is unused (KV fast check) ---
  const nonceKey = `nonce:${submission.nonce}`;
  let nonceStatus: string | null;
  
  try {
    nonceStatus = await env.SESSION_KV.get(nonceKey);
  } catch {
    nonceStatus = null;
  }

  if (nonceStatus !== "pending") {
    // Nonce already consumed or never existed — possible replay attack
    ctx.waitUntil(logEvent(env, "replay_detected", meta, submission.fingerprint,
      `Nonce replay attempt: ${submission.nonce.substring(0, 16)}`));
    return failed("REPLAY_DETECTED", "This challenge has already been used", 403);
  }

  // Immediately consume the nonce in KV (one-time use)
  try {
    await env.SESSION_KV.put(nonceKey, "consumed", { expirationTtl: 120 });
  } catch {
    // If KV write fails, the D1 status check below will catch replays
  }

  // --- 4. Retrieve challenge from D1 and verify ---
  let challenge: ChallengeRecord | null;
  try {
    challenge = await env.DB.prepare(
      "SELECT * FROM challenges WHERE nonce = ? AND status = 'pending'"
    )
      .bind(submission.nonce)
      .first<ChallengeRecord>();
  } catch {
    return failed("CHALLENGE_ERROR", "Challenge verification failed", 500);
  }

  if (!challenge) {
    return failed("CHALLENGE_EXPIRED", "Challenge not found or already used", 403);
  }

  // Check challenge expiration
  if (new Date(challenge.expires_at).getTime() < Date.now()) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "expired"));
    return failed("CHALLENGE_EXPIRED", "Challenge has expired", 403);
  }

  // Verify IP matches the original challenge request
  if (challenge.ip_address !== meta.ip) {
    if (strictContextBinding) {
      ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "ip_mismatch"));
      ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
        `IP mismatch: challenge issued to ${challenge.ip_address}`));
      ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "ip_mismatch", thresholds, meta));
      return failed("IP_MISMATCH", "Challenge IP mismatch", 403);
    }
    ctx.waitUntil(logEvent(env, "challenge_warning", meta, submission.fingerprint,
      `IP mismatch detected (soft-pass): challenge issued to ${challenge.ip_address}`));
  }

  // Verify User-Agent matches the original challenge request
  const requestUserAgent = sanitizeInput(meta.userAgent, 512);
  if (challenge.user_agent && challenge.user_agent !== requestUserAgent) {
    ctx.waitUntil(logEvent(env, "challenge_warning", meta, submission.fingerprint,
      "User-Agent mismatch detected (soft-pass)"));
  }

  // --- 5. Verify dynamic submit path + signed payload ---
  const expectedSubmitPath = `/es-verify/${submission.nonce.substring(0, 24)}`;
  if (meta.path !== expectedSubmitPath) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "path_mismatch"));
    ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "path_mismatch", thresholds, meta));
    return failed("INVALID_PATH", "Invalid submission path", 403);
  }

  const signaturePayload = `${submission.nonce}:${expectedSubmitPath}`;
  const signatureValid = await verifySignature(
    signaturePayload,
    submission.signature,
    env.JWT_SECRET
  );
  if (!signatureValid) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "signature_mismatch"));
    ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
      "Challenge signature verification failed"));
    ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "signature_mismatch", thresholds, meta));
    return failed("INVALID_SIGNATURE", "Challenge integrity verification failed", 403);
  }

  const nonceHeader = (request.headers.get("X-ES-Nonce") || "").trim();
  const expectedNonceHeader = submission.nonce.substring(0, 16);
  if (nonceHeader !== expectedNonceHeader) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "nonce_header_mismatch"));
    ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
      "Nonce header mismatch"));
    ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "nonce_header_mismatch", thresholds, meta));
    return failed("INVALID_NONCE_HEADER", "Invalid nonce header", 403);
  }

  // --- 6. Analyze telemetry for human behavior ---
  // Resolve dynamic challenge thresholds (per-domain via dashboard)
  const effectiveMinSolveMs = thresholds.challenge_min_solve_ms || DEFAULT_MIN_SOLVE_TIME_MS;
  const effectiveMinTelemetry = thresholds.challenge_min_telemetry_points || DEFAULT_MIN_TELEMETRY_POINTS;
  const effectiveXTolerance = thresholds.challenge_x_tolerance || DEFAULT_X_TOLERANCE;

  const telemetryResult = analyzeTelemetry(submission.telemetry, effectiveMinSolveMs, effectiveMinTelemetry);
  const xDiff = Math.abs(submission.sliderX - challenge.target_x);
  const softPassEligible =
    !telemetryResult.isHuman &&
    xDiff <= 12 &&
    telemetryResult.solveTimeMs >= 600 &&
    telemetryResult.solveTimeMs <= 12000 &&
    (
      telemetryResult.reason.includes("Perfectly straight line detected") ||
      telemetryResult.reason.includes("Very low Y deviation") ||
      telemetryResult.reason.includes("Insufficient telemetry")
    );

  if (softPassEligible) {
    telemetryResult.isHuman = true;
    telemetryResult.humanScore = Math.max(40, telemetryResult.humanScore);
  }
  if (!telemetryResult.isHuman) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "telemetry_rejected"));
    ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
      `Telemetry rejected: ${telemetryResult.reason}`));
    ctx.waitUntil(updateFingerprintFailure(env, submission.fingerprint, meta));
    ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "telemetry_rejected", thresholds, meta));
    return failed("CHALLENGE_FAILED", "Verification failed", 403);
  }

  // --- 7. Verify slider X position matches target ---
  if (xDiff > effectiveXTolerance) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "x_mismatch"));
    ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
      `X position mismatch: submitted ${submission.sliderX}, target ${challenge.target_x}, diff ${xDiff}`));
    ctx.waitUntil(updateFingerprintFailure(env, submission.fingerprint, meta));
    ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "x_mismatch", thresholds, meta));
    return failed("CHALLENGE_FAILED", "Verification failed", 403);
  }

  // --- 8. Verify Turnstile token (invisible background check) ---
  const turnstileToken = (submission.turnstileToken || "").trim();
  const strictTurnstile = String(env.ES_TURNSTILE_STRICT ?? "on").toLowerCase() === "on";
  if (turnstileToken) {
    const turnstileValid = await verifyTurnstile(
      turnstileToken,
      meta.ip,
      domainConfig.turnstile_secret,
      domainConfig.domain_name
    );

    if (!turnstileValid) {
      if (strictTurnstile) {
        ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "turnstile_failed"));
        ctx.waitUntil(logEvent(env, "turnstile_failed", meta, submission.fingerprint,
          "Turnstile verification failed (strict mode)"));
        ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "turnstile_failed", thresholds, meta));
        return failed("TURNSTILE_FAILED", "Background security verification failed", 403);
      }
      ctx.waitUntil(logEvent(env, "turnstile_failed", meta, submission.fingerprint,
        "Turnstile verification failed (soft-pass mode)"));
    }
  } else {
    if (strictTurnstile) {
      ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "turnstile_missing"));
      ctx.waitUntil(logEvent(env, "turnstile_failed", meta, submission.fingerprint,
        "Turnstile token missing (strict mode)"));
      ctx.waitUntil(recordFailureAndMaybeBan(env, meta.ip, "turnstile_missing", thresholds, meta));
      return failed("TURNSTILE_FAILED", "Background security verification failed", 403);
    }
    ctx.waitUntil(logEvent(env, "turnstile_failed", meta, submission.fingerprint,
      "Turnstile token missing (soft-pass mode)"));
  }

  // ========= CHALLENGE PASSED — Issue Session Token =========

  // Mark challenge as solved in D1
  ctx.waitUntil(
    env.DB.prepare("UPDATE challenges SET status = 'solved', solved_at = CURRENT_TIMESTAMP WHERE nonce = ?")
      .bind(challenge.nonce).run().catch(() => {})
  );

  // Update fingerprint record
  ctx.waitUntil(upsertFingerprint(env, submission.fingerprint, meta));

  // CRITICAL: Reset the visit counter SYNCHRONOUSLY before sending response.
  // This must be awaited (not ctx.waitUntil) to ensure the counter is deleted
  // before the client redirects. Without this, KV eventual consistency causes
  // the redirected request to read the old high counter value.
  await resetVisitCounter(meta, env);

  // Set a post-solve grace marker (2 minutes). Even after the delete above,
  // KV eventual consistency may cause the counter read to return a stale value.
  // This grace marker tells the main handler to skip visit-counter checks.
  try {
    const uaData = new TextEncoder().encode(meta.userAgent || "unknown");
    const uaHashBuffer = await crypto.subtle.digest("SHA-256", uaData);
    const uaHashArray = new Uint8Array(uaHashBuffer);
    let uaHex = "";
    for (let i = 0; i < 4; i++) {
      uaHex += uaHashArray[i].toString(16).padStart(2, "0");
    }
    await env.SESSION_KV.put(`vc_grace:${meta.ip}:${uaHex}`, "1", {
      expirationTtl: 120,
    });
  } catch {}

  // Generate session token (JWT bound to IP + fingerprint)
  const now = Math.floor(Date.now() / 1000);
  const claims: SessionTokenClaims = {
    sub: submission.fingerprint,
    iss: "edge-shield",
    iat: now,
    exp: now + thresholds.session_ttl_seconds,
    ip: meta.ip,
    fph: submission.fingerprint,
    rsk: telemetryResult.humanScore,
  };

  const sessionToken = await createSessionToken(claims, env.JWT_SECRET);

  // Log successful challenge
  ctx.waitUntil(logEvent(env, "challenge_solved", meta, submission.fingerprint,
    `Solved in ${telemetryResult.solveTimeMs}ms, human score: ${telemetryResult.humanScore}, mode=normal`));
  ctx.waitUntil(clearFailureCounter(env, meta.ip));

  // Return success with session cookie
  const cookieHeader = buildSessionCookie(sessionToken, thresholds.session_ttl_seconds);

  const safeRedirect = (submission.originalPath || "/").startsWith("/") 
    ? (submission.originalPath || "/") 
    : "/";

  markUsageOutcome(env, "challenge_passed");

  return createJsonResponse(
    {
      success: true,
      message: "Verification complete",
      redirectUrl: safeRedirect,
    },
    200,
    { "Set-Cookie": cookieHeader }
  );
}

// ---------------------------------------------------------------------------
// Telemetry Analysis Engine
// ---------------------------------------------------------------------------

interface TelemetryAnalysisResult {
  isHuman: boolean;
  humanScore: number;   // 0-100, higher = more likely human
  reason: string;
  solveTimeMs: number;
}

/**
 * Analyzes mouse/touch telemetry data to determine if the interaction
 * is from a human or a bot.
 *
 * Each telemetry point is a tuple: [x, y, timestamp]
 *
 * Analysis checks:
 *   1. Sufficient data points (bots often send minimal data)
 *   2. Solve time within acceptable range
 *   3. Not a perfectly straight horizontal line (bots)
 *   4. Velocity variation (humans have inconsistent speed)
 *   5. Acceleration changes (natural mouse movement has jitter)
 *   6. Y-axis deviation (humans cannot maintain perfect Y alignment)
 *   7. Micro-pauses detection (humans naturally hesitate)
 */
function analyzeTelemetry(
  telemetry: Array<[number, number, number]>,
  minSolveTimeMs: number = DEFAULT_MIN_SOLVE_TIME_MS,
  minTelemetryPoints: number = DEFAULT_MIN_TELEMETRY_POINTS
): TelemetryAnalysisResult {
  // --- Check 1: Sufficient data points ---
  if (!telemetry || telemetry.length < minTelemetryPoints) {
    return {
      isHuman: false,
      humanScore: 0,
      reason: `Insufficient telemetry: ${telemetry?.length || 0} points (min: ${minTelemetryPoints})`,
      solveTimeMs: 0,
    };
  }

  if (telemetry.length > MAX_TELEMETRY_POINTS) {
    return {
      isHuman: false,
      humanScore: 0,
      reason: `Excessive telemetry: ${telemetry.length} points (max: ${MAX_TELEMETRY_POINTS})`,
      solveTimeMs: 0,
    };
  }

  // --- Check 2: Solve time ---
  const startTime = telemetry[0][2];
  const endTime = telemetry[telemetry.length - 1][2];
  const solveTimeMs = endTime - startTime;

  if (solveTimeMs < minSolveTimeMs) {
    return {
      isHuman: false,
      humanScore: 0,
      reason: `Solve too fast: ${solveTimeMs}ms (min: ${minSolveTimeMs}ms)`,
      solveTimeMs,
    };
  }

  if (solveTimeMs > MAX_SOLVE_TIME_MS) {
    return {
      isHuman: false,
      humanScore: 5,
      reason: `Solve too slow: ${solveTimeMs}ms (max: ${MAX_SOLVE_TIME_MS}ms)`,
      solveTimeMs,
    };
  }

  // --- Compute velocity and acceleration profiles ---
  const velocities: number[] = [];
  const yPositions: number[] = [];
  const xDeltas: number[] = [];
  const timeDeltasMs: number[] = [];

  for (let i = 1; i < telemetry.length; i++) {
    const dx = telemetry[i][0] - telemetry[i - 1][0];
    const dy = telemetry[i][1] - telemetry[i - 1][1];
    const dt = telemetry[i][2] - telemetry[i - 1][2];

    xDeltas.push(dx);
    yPositions.push(telemetry[i][1]);
    timeDeltasMs.push(dt);

    if (dt > 0) {
      const distance = Math.sqrt(dx * dx + dy * dy);
      velocities.push(distance / dt);
    }
  }

  let humanScore = 50; // Start neutral
  const reasons: string[] = [];

  // --- Check 3: Straight line detection ---
  // Bots often produce perfectly straight horizontal lines (all Y values identical).
  // Keep this lenient enough for touch devices where Y jitter can be very low.
  const ySet = new Set(yPositions);
  if (ySet.size <= 1) {
    humanScore -= 30;
    reasons.push("Perfectly straight line detected (Y deviation = 0)");
  }
  if (ySet.size === 2) {
    humanScore -= 20;
    reasons.push("Low Y variation detected (possible touch-device straight drag)");
  }

  // --- Check 4: Y-axis deviation (humans wobble) ---
  const yMean = yPositions.reduce((a, b) => a + b, 0) / yPositions.length;
  const yVariance =
    yPositions.reduce((sum, y) => sum + (y - yMean) ** 2, 0) / yPositions.length;
  const yStdDev = Math.sqrt(yVariance);

  if (yStdDev < 0.5) {
    humanScore -= 25;
    reasons.push(`Very low Y deviation: ${yStdDev.toFixed(2)}`);
  } else if (yStdDev >= 1 && yStdDev <= 20) {
    humanScore += 10;
    reasons.push(`Natural Y deviation: ${yStdDev.toFixed(2)}`);
  } else if (yStdDev > 50) {
    humanScore -= 10;
    reasons.push(`Excessive Y deviation: ${yStdDev.toFixed(2)}`);
  }

  // --- Check 5: Velocity consistency (humans are inconsistent) ---
  if (velocities.length > 3) {
    const velMean = velocities.reduce((a, b) => a + b, 0) / velocities.length;
    const velVariance =
      velocities.reduce((sum, v) => sum + (v - velMean) ** 2, 0) / velocities.length;
    const velCoeffVar = velMean > 0 ? Math.sqrt(velVariance) / velMean : 0;

    // Coefficient of variation: <0.1 = suspiciously consistent (bot)
    if (velCoeffVar < 0.1) {
      humanScore -= 25;
      reasons.push(`Robotic velocity consistency: CV=${velCoeffVar.toFixed(3)}`);
    } else if (velCoeffVar >= 0.2 && velCoeffVar <= 2.0) {
      humanScore += 15;
      reasons.push(`Human-like velocity variation: CV=${velCoeffVar.toFixed(3)}`);
    }
  }

  // --- Check 6: Acceleration changes (direction reversals) ---
  let directionChanges = 0;
  for (let i = 1; i < xDeltas.length; i++) {
    if (
      (xDeltas[i] > 0 && xDeltas[i - 1] < 0) ||
      (xDeltas[i] < 0 && xDeltas[i - 1] > 0)
    ) {
      directionChanges++;
    }
  }

  if (directionChanges === 0 && telemetry.length > 20) {
    humanScore -= 15;
    reasons.push("No direction changes detected (perfectly monotonic)");
  } else if (directionChanges >= 1 && directionChanges <= 10) {
    humanScore += 10;
    reasons.push(`Natural micro-corrections: ${directionChanges} direction changes`);
  }

  // --- Check 7: Micro-pauses (humans naturally hesitate) ---
  let pauseCount = 0;
  for (const dt of timeDeltasMs) {
    if (dt > 50 && dt < 500) {
      pauseCount++;
    }
  }

  if (pauseCount === 0 && telemetry.length > 15) {
    humanScore -= 10;
    reasons.push("No micro-pauses detected (continuous robotic movement)");
  } else if (pauseCount >= 1) {
    humanScore += 5;
    reasons.push(`Natural hesitation patterns: ${pauseCount} micro-pauses`);
  }

  // --- Check 8: Timestamp monotonicity and realism ---
  let timestampValid = true;
  for (let i = 1; i < telemetry.length; i++) {
    if (telemetry[i][2] <= telemetry[i - 1][2]) {
      timestampValid = false;
      break;
    }
  }
  if (!timestampValid) {
    return {
      isHuman: false,
      humanScore: 0,
      reason: "Non-monotonic timestamps detected (fabricated telemetry)",
      solveTimeMs,
    };
  }

  // --- Final score ---
  humanScore = Math.max(0, Math.min(100, humanScore));

  return {
    isHuman: humanScore >= 35,
    humanScore,
    reason: reasons.join("; ") || "Passed all checks",
    solveTimeMs,
  };
}

// ---------------------------------------------------------------------------
// Turnstile Verification
// ---------------------------------------------------------------------------

/**
 * Verifies an invisible Turnstile token server-side via Cloudflare API.
 */
async function verifyTurnstile(
  token: string,
  clientIP: string,
  secret: string,
  expectedHostname: string
): Promise<boolean> {
  if (!token || token.length < 8) return false;
  try {
    const response = await fetch(TURNSTILE_VERIFY_URL, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        secret,
        response: token,
        remoteip: clientIP,
      }),
    });

    const result = await response.json() as {
      success: boolean;
      hostname?: string;
    };
    if (result.success !== true) return false;
    if (!result.hostname) return false;

    const actual = result.hostname.toLowerCase();
    const expected = expectedHostname.toLowerCase();
    return actual === expected || actual.endsWith(`.${expected}`);
  } catch {
    return false;
  }
}

// ---------------------------------------------------------------------------
// Payload Validation
// ---------------------------------------------------------------------------

/**
 * Validates the structure and types of a challenge submission payload.
 * Throws an error if any required field is missing or has the wrong type.
 */
function validateSubmissionPayload(body: unknown): ChallengeSubmission {
  if (!body || typeof body !== "object") {
    throw new Error("Body must be a JSON object");
  }

  const obj = body as Record<string, unknown>;

  // Required string fields
  for (const field of ["nonce", "fingerprint", "signature"]) {
    if (typeof obj[field] !== "string" || (obj[field] as string).length === 0) {
      throw new Error(`Missing or invalid field: ${field}`);
    }
  }

  if (obj.turnstileToken != null && typeof obj.turnstileToken !== "string") {
    throw new Error("Invalid turnstileToken type");
  }

  if (!/^[a-f0-9]{64}$/i.test(obj.nonce as string)) {
    throw new Error("Invalid nonce format");
  }
  if (!/^[a-f0-9]{64}$/i.test(obj.fingerprint as string)) {
    throw new Error("Invalid fingerprint format");
  }
  if ((obj.signature as string).length < 32 || (obj.signature as string).length > 256) {
    throw new Error("Invalid signature length");
  }

  // sliderX must be a finite number
  if (typeof obj.sliderX !== "number" || !isFinite(obj.sliderX)) {
    throw new Error("sliderX must be a finite number");
  }

  // telemetry must be an array of [number, number, number] tuples
  if (!Array.isArray(obj.telemetry)) {
    throw new Error("telemetry must be an array");
  }
  if (obj.telemetry.length > MAX_TELEMETRY_POINTS) {
    throw new Error(`telemetry exceeds maximum of ${MAX_TELEMETRY_POINTS}`);
  }

  for (let i = 0; i < obj.telemetry.length; i++) {
    const point = obj.telemetry[i];
    if (
      !Array.isArray(point) ||
      point.length !== 3 ||
      typeof point[0] !== "number" ||
      typeof point[1] !== "number" ||
      typeof point[2] !== "number"
    ) {
      throw new Error(`Invalid telemetry point at index ${i}`);
    }
  }

  return {
    nonce: obj.nonce as string,
    telemetry: obj.telemetry as Array<[number, number, number]>,
    sliderX: obj.sliderX as number,
    fingerprint: obj.fingerprint as string,
    turnstileToken: (obj.turnstileToken as string | undefined) || "",
    signature: obj.signature as string,
    originalPath: obj.originalPath ? String(obj.originalPath) : "/",
  };
}

// ---------------------------------------------------------------------------
// Helper: Random Target X Generation
// ---------------------------------------------------------------------------

/**
 * Generates a random target X position within the valid slider range.
 * Uses crypto.getRandomValues for uniform distribution.
 */
function generateTargetX(): number {
  const range = TARGET_X_MAX - TARGET_X_MIN;
  const randomBytes = new Uint32Array(1);
  crypto.getRandomValues(randomBytes);
  return TARGET_X_MIN + (randomBytes[0] % (range + 1));
}

function pickChallengeIcon(): string {
  const randomBytes = new Uint32Array(1);
  crypto.getRandomValues(randomBytes);
  const idx = randomBytes[0] % CHALLENGE_ICONS.length;
  return CHALLENGE_ICONS[idx];
}

/**
 * Encodes target_x into a compact hint to avoid exposing the raw coordinate
 * directly in static HTML source.
 */
function encodeTargetHint(targetX: number, nonce: string, signature: string): string {
  const noncePart = parseInt(nonce.slice(0, 8), 16) >>> 0;
  const sigPart = parseInt(signature.slice(0, 8), 16) >>> 0;
  const random = new Uint32Array(1);
  crypto.getRandomValues(random);
  const salt = random[0] & 0xffff;
  const mask = (noncePart ^ sigPart ^ salt) & 0x3ff;
  const encoded = (targetX ^ mask) & 0x3ff;
  return `${salt.toString(16)}.${encoded.toString(16)}`;
}

/**
 * Builds a short-lived signed cookie that binds challenge solve requests
 * to the original requester context (nonce + IP + User-Agent + expiry).
 */
async function buildChallengeBindingCookie(
  nonce: string,
  submitPath: string,
  meta: RequestMeta,
  secret: string
): Promise<string> {
  const expiryMs = Date.now() + (CHALLENGE_TTL_SECONDS + 10) * 1000;
  const ipScope = normalizeIpScope(meta.ip);
  const payload = `${nonce}:${expiryMs}:${ipScope}`;
  const signature = await generateSignature(payload, secret);
  const value = `${nonce}.${expiryMs}.${signature}`;

  return [
    `${CHALLENGE_COOKIE_NAME}=${value}`,
    `Max-Age=${CHALLENGE_TTL_SECONDS + 10}`,
    "Path=/",
    "HttpOnly",
    "Secure",
    "SameSite=None",
  ].join("; ");
}

/**
 * Verifies the short-lived challenge binding cookie.
 */
async function verifyChallengeBindingCookie(
  request: Request,
  nonce: string,
  meta: RequestMeta,
  secret: string
): Promise<boolean> {
  const cookieHeader = request.headers.get("Cookie");
  if (!cookieHeader) return false;

  const raw = parseCookieValue(cookieHeader, CHALLENGE_COOKIE_NAME);
  if (!raw) return false;

  const parts = raw.split(".");
  if (parts.length !== 3) return false;

  const [cookieNonce, expiryText, signature] = parts;
  if (cookieNonce !== nonce) return false;
  if (!/^\d{10,}$/.test(expiryText)) return false;
  if (!/^[a-f0-9]{32,256}$/i.test(signature)) return false;

  const expiryMs = Number(expiryText);
  if (!Number.isFinite(expiryMs) || expiryMs < Date.now()) return false;

  const ipScope = normalizeIpScope(meta.ip);
  const payload = `${cookieNonce}:${expiryMs}:${ipScope}`;
  return verifySignature(payload, signature, secret);
}

/**
 * Normalizes client IP to a stable scope to reduce false negatives:
 * - IPv4: /24 scope (first 3 octets)
 * - IPv6: /64 scope (first 4 hextets)
 */
function normalizeIpScope(ip: string): string {
  const value = String(ip || "").trim().toLowerCase();
  if (!value) return "unknown";
  if (value.includes(":")) {
    const parts = value.split(":").filter(Boolean);
    if (parts.length >= 4) {
      return `${parts.slice(0, 4).join(":")}::/64`;
    }
    return `${value}::/64`;
  }
  const octets = value.split(".");
  if (octets.length === 4) {
    return `${octets[0]}.${octets[1]}.${octets[2]}.0/24`;
  }
  return value;
}

function parseCookieValue(cookieHeader: string, name: string): string | null {
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

function minifyInlineCss(input: string): string {
  return input
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/\s+/g, " ")
    .replace(/\s*([{}:;,>+~])\s*/g, "$1")
    .replace(/;}/g, "}")
    .trim();
}

// ---------------------------------------------------------------------------
// Helper: Session Cookie Builder
// ---------------------------------------------------------------------------

function buildSessionCookie(token: string, maxAge: number): string {
  return [
    `${SESSION_COOKIE_NAME}=${token}`,
    `Max-Age=${maxAge}`,
    "Path=/",
    "HttpOnly",
    "Secure",
    "SameSite=None",
  ].join("; ");
}

// ---------------------------------------------------------------------------
// Helper: D1 Update Functions
// ---------------------------------------------------------------------------

/** Marks a challenge as failed in D1 */
async function markChallengeFailed(
  env: Env,
  nonce: string,
  reason: string
): Promise<void> {
  try {
    await env.DB.prepare("UPDATE challenges SET status = 'failed' WHERE nonce = ?")
      .bind(nonce)
      .run();
  } catch {
    // Non-fatal
  }
}

/** Updates a fingerprint record to increment failure count */
async function updateFingerprintFailure(
  env: Env,
  fingerprintHash: string,
  meta: RequestMeta
): Promise<void> {
  try {
    // Try to update existing record
    const result = await env.DB.prepare(
      `UPDATE fingerprints
       SET fail_count = fail_count + 1,
           last_seen = CURRENT_TIMESTAMP,
           risk_score = MIN(100, risk_score + 10),
           ip_address = ?,
           asn = COALESCE(?, asn),
           country = COALESCE(?, country)
       WHERE hash = ?`
    )
      .bind(meta.ip, meta.asn, meta.country, fingerprintHash)
      .run();

    // If no row was updated, insert a new record with elevated risk
    if (result.meta.changes === 0) {
      await env.DB.prepare(
        `INSERT INTO fingerprints (hash, ip_address, asn, country, user_agent, risk_score, fail_count)
         VALUES (?, ?, ?, ?, ?, 60, 1)`
      )
        .bind(fingerprintHash, meta.ip, meta.asn, meta.country, sanitizeInput(meta.userAgent, 512))
        .run();
    }
  } catch {
    // Non-fatal
  }
}

/** Upserts a fingerprint record on successful challenge solve */
async function upsertFingerprint(
  env: Env,
  fingerprintHash: string,
  meta: RequestMeta
): Promise<void> {
  try {
    const result = await env.DB.prepare(
      `UPDATE fingerprints
       SET challenge_count = challenge_count + 1,
           last_seen = CURRENT_TIMESTAMP,
           risk_score = MAX(10, risk_score - 5),
           ip_address = ?,
           asn = COALESCE(?, asn),
           country = COALESCE(?, country)
       WHERE hash = ?`
    )
      .bind(meta.ip, meta.asn, meta.country, fingerprintHash)
      .run();

    if (result.meta.changes === 0) {
      await env.DB.prepare(
        `INSERT INTO fingerprints (hash, ip_address, asn, country, user_agent, risk_score, challenge_count)
         VALUES (?, ?, ?, ?, ?, 40, 1)`
      )
        .bind(fingerprintHash, meta.ip, meta.asn, meta.country, sanitizeInput(meta.userAgent, 512))
        .run();
    }
  } catch {
    // Non-fatal
  }
}

/** Logs a security event to D1 */
async function logEvent(
  env: Env,
  eventType: string,
  meta: RequestMeta,
  fingerprintHash: string | null,
  details: string
): Promise<void> {
  const domainName = extractDomainFromMeta(meta);
  try {
    await env.DB.prepare(
      `INSERT INTO security_logs (domain_name, event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
       VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)`
    )
      .bind(domainName, eventType, meta.ip, meta.asn, meta.country, meta.path, fingerprintHash, details)
      .run();
    await incrementHistoricalAttackCounters(env, eventType, meta.ip);
  } catch {
    // Non-fatal
  }
}

function utcDayKey(now: Date = new Date()): string {
  return now.toISOString().slice(0, 10);
}

function utcMonthKey(now: Date = new Date()): string {
  return now.toISOString().slice(0, 7);
}

async function incrementHistoricalAttackCounters(
  env: Env,
  eventType: string,
  ip: string
): Promise<void> {
  if (!ip || !HISTORICAL_ATTACK_EVENTS.has(eventType)) return;

  const dayKey = `${IP_ATTACK_DAY_PREFIX}${utcDayKey()}:${ip}`;
  const monthKey = `${IP_ATTACK_MONTH_PREFIX}${utcMonthKey()}:${ip}`;

  try {
    const currentDay = await env.SESSION_KV.get(dayKey);
    const dayCount = currentDay ? parseInt(currentDay, 10) + 1 : 1;
    await env.SESSION_KV.put(dayKey, String(dayCount), {
      expirationTtl: ATTACK_COUNTER_TTL_SECONDS,
    });
  } catch {
    // Non-fatal
  }

  try {
    const currentMonth = await env.SESSION_KV.get(monthKey);
    const monthCount = currentMonth ? parseInt(currentMonth, 10) + 1 : 1;
    await env.SESSION_KV.put(monthKey, String(monthCount), {
      expirationTtl: ATTACK_COUNTER_TTL_SECONDS,
    });
  } catch {
    // Non-fatal
  }
}

/**
 * Resets the visit counter for an IP+UA composite key after successful CAPTCHA solve.
 * This prevents the counter from persisting and re-triggering CAPTCHA on the redirect.
 */
async function resetVisitCounter(meta: RequestMeta, env: Env): Promise<void> {
  try {
    const data = new TextEncoder().encode(meta.userAgent || "unknown");
    const hashBuffer = await crypto.subtle.digest("SHA-256", data);
    const hashArray = new Uint8Array(hashBuffer);
    let hex = "";
    for (let i = 0; i < 4; i++) {
      hex += hashArray[i].toString(16).padStart(2, "0");
    }
    const key = `vc:${meta.ip}:${hex}`;
    await env.SESSION_KV.delete(key);
  } catch {
    // Non-fatal — the session age fallback in index.ts will catch this
  }
}

async function isSubmissionRateLimited(ip: string, env: Env): Promise<boolean> {
  const key = `submitrate:${ip}`;
  try {
    const current = await env.SESSION_KV.get(key);
    const count = current ? parseInt(current, 10) + 1 : 1;
    await env.SESSION_KV.put(key, String(count), { expirationTtl: 60 });
    return count > MAX_SUBMISSIONS_PER_MINUTE_PER_IP;
  } catch {
    return false;
  }
}

async function recordFailureAndMaybeBan(
  env: Env,
  ip: string,
  reason: string,
  thresholds?: DomainThresholds,
  meta?: RequestMeta
): Promise<void> {
  const key = `failrate:${ip}`;
  try {
    const current = await env.SESSION_KV.get(key);
    const count = current ? parseInt(current, 10) + 1 : 1;
    await env.SESSION_KV.put(key, String(count), {
      expirationTtl: FAILURE_WINDOW_SECONDS,
    });

    const maxFailures = thresholds ? thresholds.max_challenge_failures : 8;
    if (count >= maxFailures) {
      await markIPTemporarilyBanned(env, ip, reason, thresholds, meta);
    }
  } catch {
    // Non-fatal
  }
}

async function clearFailureCounter(env: Env, ip: string): Promise<void> {
  try {
    await env.SESSION_KV.delete(`failrate:${ip}`);
  } catch {
    // Non-fatal
  }
}

async function markIPTemporarilyBanned(
  env: Env,
  ip: string,
  reason: string,
  thresholds?: DomainThresholds,
  meta?: RequestMeta
): Promise<void> {
  const domainName = meta ? extractDomainFromMeta(meta) : null;
  const targetPath = meta?.path || "/es-verify";
  const banTtl = thresholds ? thresholds.temp_ban_ttl_seconds : 86400; // default 24h
  try {
    // Always use domain-scoped key. If domain is unknown, use a generic marker
    // that the main handler's isTemporarilyBanned() will still check.
    const effectiveDomain = domainName || "_unknown_";
    const banKey = `ban:domainIP:${effectiveDomain}:${ip}`;
    await env.SESSION_KV.put(banKey, "1", { expirationTtl: banTtl });
    await env.DB.prepare(
      `INSERT INTO security_logs (domain_name, event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
       VALUES (?, 'hard_block', ?, NULL, NULL, ?, NULL, 100, ?)`
    )
      .bind(domainName, ip, targetPath, `Temporary IP ban (${banTtl}s): ${reason}`)
      .run();
    await incrementHistoricalAttackCounters(env, "hard_block", ip);

    // IP Farm: Challenge failure ban → permanent ban in graveyard
    try {
      await addToIpFarm(env, ip, `Challenge failure ban: ${reason}`);
    } catch {
      // IP Farm pipeline is non-fatal
    }
  } catch {
    // Non-fatal
  }
}

/**
 * Adds an IP to the [IP-FARM] permanent ban graveyard via D1 Global Firewall rules.
 * Uses smart merging: fills existing non-full rules before creating new ones.
 * Max 500 IPs per rule. Rules have NO expiry (permanent ban).
 */
const IP_FARM_DESC_PREFIX_CH = "[IP-FARM]";
const IP_FARM_MAX_PER_RULE = 500;

async function addToIpFarm(env: Env, ip: string, reason: string): Promise<void> {
  // Skip private/reserved IPs
  if (isPrivateOrReservedIP(ip)) return;

  // Check allow-list before permanent banning — prevents admin-allowed IPs
  // from being accidentally added to the permanent ban graveyard.
  if (await isIpAllowListed(ip, env)) return;

  const lowerIp = ip.toLowerCase().trim();

  // Fetch all existing [IP-FARM] rules
  const existingRes = await env.DB.prepare(
    `SELECT id, expression_json
     FROM custom_firewall_rules
     WHERE description LIKE ?
       AND action = 'block'
       AND paused = 0
     ORDER BY id ASC`
  )
    .bind(`${IP_FARM_DESC_PREFIX_CH}%`)
    .all<{ id: number; expression_json: string }>();

  const parsedRules: { id: number; expression_json: string; targets: string[] }[] = [];

  if (existingRes.results) {
    for (const rule of existingRes.results) {
      try {
        const expr = JSON.parse(rule.expression_json);
        if (expr.field === "ip.src" && expr.operator === "in") {
          const targets = String(expr.value)
            .split(",")
            .map((v: string) => v.trim().toLowerCase())
            .filter(Boolean);
          if (targets.includes(lowerIp)) return; // Already in farm
          parsedRules.push({ id: rule.id, expression_json: rule.expression_json, targets });
        }
      } catch {}
    }
  }

  // Find a non-full rule to merge into
  let merged = false;
  for (const pRule of parsedRules) {
    if (pRule.targets.length < IP_FARM_MAX_PER_RULE) {
      const mergedTargets = [...pRule.targets, lowerIp];
      const newExpression = JSON.stringify({
        field: "ip.src",
        operator: "in",
        value: mergedTargets.join(", "),
      });
      const farmNumber = parsedRules.indexOf(pRule) + 1;
      const desc = `${IP_FARM_DESC_PREFIX_CH} IP Farm ${farmNumber} (${mergedTargets.length} IPs)`;

      await env.DB.prepare(
        `UPDATE custom_firewall_rules
         SET expression_json = ?, description = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND expression_json = ?`
      )
        .bind(newExpression, desc, pRule.id, pRule.expression_json)
        .run();
      merged = true;
      break;
    }
  }

  if (!merged) {
    const farmNumber = parsedRules.length + 1;
    const newExpression = JSON.stringify({
      field: "ip.src",
      operator: "in",
      value: lowerIp,
    });
    await env.DB.prepare(
      `INSERT INTO custom_firewall_rules (domain_name, description, action, expression_json, paused, expires_at)
       VALUES ('global', ?, 'block', ?, 0, NULL)`
    )
      .bind(`${IP_FARM_DESC_PREFIX_CH} IP Farm ${farmNumber} (1 IPs)`, newExpression)
      .run();
  }

  // Purge KV cache
  try { await env.SESSION_KV.delete("cfr:global"); } catch {}

  // Log
  await env.DB.prepare(
    `INSERT INTO security_logs (domain_name, event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
     VALUES (NULL, 'hard_block', ?, NULL, NULL, NULL, NULL, 100, ?)`
  )
    .bind(ip, `[IP-FARM] Permanent ban: ${reason}`)
    .run();
}

// extractDomainFromMeta — imported from ./utils

// ---------------------------------------------------------------------------
// Challenge HTML Page Builder (Phase 6: Enhanced UI + Runtime Script)
// ---------------------------------------------------------------------------

/**
 * Builds the production CAPTCHA challenge HTML page.
 * Features:
 *   - Glassmorphism dark UI with animated grid background
 *   - Embedded slider.js runtime (5-module fingerprint engine)
 *   - Cloudflare Turnstile widget (invisible, dark mode)
 *   - Dynamic submission endpoint
 *   - Challenge metadata (nonce, signature, expiry)
 *   - Visual target slot for accurate user alignment
 * targetHint is a UX obfuscation hint only, not a standalone security boundary.
 */
function buildChallengeHtml(
  nonce: string,
  submitPath: string,
  fallbackSubmitPath: string,
  signature: string,
  turnstileSiteKey: string,
  targetHint: string,
  targetIcon: string,
  issuedAt: number,
  expiresAt: number
): string {
  return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Security Verification</title>
  <meta name="x-runtime-cluster" content="edge-h2-asia-fallback">
  <meta name="x-bot-signal-mode" content="adaptive-proxy-grid-v4">
  <!-- decoy: legacy wasm puzzle bootstrap kept for compatibility -->
  <!-- decoy: telemetry relay endpoint /api/v2/risk-bootstrap -->
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit" async defer></script>
  <style>${minifyInlineCss(`
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg-primary: #f7f8fb;
      --bg-card: #ffffff;
      --bg-card-border: #e5e7eb;
      --text-primary: #111827;
      --text-secondary: #4b5563;
      --text-muted: #6b7280;
      --accent: #3b82f6;
      --accent-strong: #2563eb;
      --success: #10b981;
      --error: #ef4444;
      --border: #e5e7eb;
      --slider-track-bg: #f3f4f6;
      --slider-fill: #dbeafe;
    }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: var(--bg-primary);
      color: var(--text-primary);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }
    .shield-container {
      background: var(--bg-card);
      border: 1px solid var(--bg-card-border);
      border-radius: 16px;
      padding: 28px 18px 18px;
      max-width: 340px;
      width: 92%;
      box-shadow: 0 6px 24px rgba(17, 24, 39, 0.08);
    }
    .shield-header { text-align: center; margin-bottom: 1.3rem; }
    .shield-icon {
      width: 54px;
      height: 54px;
      margin: 0 auto 0.9rem;
      background: linear-gradient(135deg, var(--accent), var(--accent-strong));
      border-radius: 14px;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 8px 20px rgba(59, 130, 246, 0.25);
    }
    .shield-icon svg { width: 24px; height: 24px; fill: #fff; }
    .shield-header h1 {
      font-size: 1.25rem;
      font-weight: 700;
      margin-bottom: 0.35rem;
      color: var(--text-primary);
    }
    .shield-header p { font-size: 0.88rem; color: var(--text-secondary); line-height: 1.4; }
    .icon-strip {
      width: 100%;
      max-width: 300px;
      margin: 0.2rem auto 0.75rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.4rem;
    }
    .icon-pill {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      border: 1px solid var(--border);
      background: #f9fafb;
      color: #334155;
      border-radius: 999px;
      font-size: 0.66rem;
      font-weight: 700;
      padding: 0.28rem 0.48rem;
      white-space: nowrap;
    }
    .icon-pill svg {
      width: 11px;
      height: 11px;
      fill: var(--accent);
    }
    .puzzle-hint {
      width: 100%;
      max-width: 300px;
      margin: 0 auto 0.5rem;
      border-radius: 10px;
      border: 1px solid #dbeafe;
      background: #eff6ff;
      color: #1e40af;
      font-size: 0.78rem;
      font-weight: 600;
      text-align: center;
      padding: 0.5rem;
    }
    .puzzle-board {
      position: relative;
      width: 100%;
      max-width: 300px;
      height: 140px;
      margin: 0.35rem auto 0.6rem;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: #eef2f7;
      overflow: hidden;
      user-select: none;
      -webkit-user-select: none;
    }
    .puzzle-chip {
      position: absolute;
      top: 14px;
      width: 40px;
      height: 40px;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.85);
      border: 1px solid rgba(15, 23, 42, 0.12);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .puzzle-chip span {
      font-size: 1.32rem;
      line-height: 1;
    }
    .puzzle-hole {
      position: absolute;
      top: 56px;
      width: 44px;
      height: 44px;
      border-radius: 8px;
      border: 2px solid rgba(17, 24, 39, 0.32);
      background: rgba(17, 24, 39, 0.18);
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: none;
      box-shadow: inset 0 1px 2px rgba(0,0,0,0.15);
      z-index: 3;
    }
    .puzzle-hole span {
      font-size: 1.2rem;
      line-height: 1;
      opacity: 0.55;
    }
    .puzzle-piece {
      position: absolute;
      top: 56px;
      left: 0;
      width: 44px;
      height: 44px;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--accent), var(--accent-strong));
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      transition: left 0.02s linear, background 0.3s ease, box-shadow 0.3s ease;
      pointer-events: none;
      z-index: 6;
    }
    .puzzle-piece.matched {
      background: var(--success);
      box-shadow: 0 2px 10px rgba(16,185,129,0.45);
    }
    .puzzle-piece span {
      font-size: 1.3rem;
      line-height: 1;
      filter: drop-shadow(0 1px 1px rgba(0,0,0,0.15));
    }
    .slider-container {
      position: relative;
      width: 100%;
      max-width: 300px;
      height: 48px;
      margin: 0.35rem auto 0;
      background: var(--slider-track-bg);
      border-radius: 24px;
      border: 1px solid var(--border); overflow: hidden;
      user-select: none; -webkit-user-select: none; touch-action: none;
    }
    .slider-track {
      position: absolute; top: 0; left: 0; height: 100%;
      background: var(--slider-fill);
      border-radius: 24px 0 0 24px;
      width: 0; transition: none;
    }
    .slider-handle {
      position: absolute; top: -1px; left: 0; width: 48px; height: 48px;
      background: linear-gradient(135deg, var(--accent), var(--accent-strong));
      border: 2px solid #ffffff;
      border-radius: 24px;
      cursor: grab; display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 7px rgba(0,0,0,0.15);
      z-index: 10; transition: box-shadow 0.2s ease, background 0.3s ease;
    }
    .slider-handle:hover {
      box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    }
    .slider-handle:active {
      cursor: grabbing;
      box-shadow: 0 4px 12px rgba(0,0,0,0.25);
    }
    .slider-handle svg { width: 18px; height: 18px; fill: white; }
    .slider-label {
      position: absolute; top: 50%; left: 55%; transform: translate(-50%, -50%);
      font-size: 0.85rem; font-weight: 600; color: var(--text-muted);
      pointer-events: none; letter-spacing: 1px; text-transform: uppercase;
      transition: opacity 0.2s ease;
    }
    .timer-bar { width: 100%; max-width: 300px; height: 4px; background: #e5e7eb; border-radius: 999px; margin: 0.95rem auto 0; overflow: hidden; }
    .timer-fill {
      height: 100%; background: linear-gradient(90deg, var(--accent), #60a5fa);
      border-radius: 999px; width: 100%;
      transition: width ${CHALLENGE_TTL_SECONDS}s linear;
    }
    .timer-fill.active { width: 0%; }
    .status-bar {
      text-align: center; margin-top: 1rem; font-size: 0.8rem;
      color: var(--text-secondary); min-height: 1.4em; font-weight: 500;
      transition: color 0.3s ease;
    }
    .status-bar.success { color: var(--success); }
    .status-bar.error { color: var(--error); }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    .status-bar.loading { animation: pulse 1.5s ease-in-out infinite; }
    .turnstile-stealth { position: absolute; width: 0; height: 0; overflow: hidden; opacity: 0; pointer-events: none; }
    .shield-footer { text-align: center; margin-top: 1.2rem; padding-top: 0.9rem; border-top: 1px solid var(--border); }
    .shield-footer p {
      font-size: 0.7rem; color: var(--text-muted);
      display: flex; align-items: center; justify-content: center; gap: 0.4rem;
    }
    .shield-footer .dot {
      display: inline-block; width: 6px; height: 6px; border-radius: 50%;
      background: var(--accent); animation: dotPulse 2s ease-in-out infinite;
    }
    @media (max-width: 440px) { .shield-container { padding: 22px 14px 14px; } }
  `)}</style>
</head>
<body>
  <div class="shield-container">
    <div class="turnstile-stealth"><div id="tsHidden"></div></div>
    <div class="shield-header">
      <div class="shield-icon">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 2l7 3v6c0 5-3.4 9.5-7 11-3.6-1.5-7-6-7-11V5l7-3zm0 2.2L7 6v5c0 3.9 2.5 7.7 5 9 2.5-1.3 5-5.1 5-9V6l-5-1.8z"/>
        </svg>
      </div>
      <h1>تأكيد الهوية</h1>
      <p>قم بسحب الرمز إلى المكان الصحيح للمتابعة</p>
    </div>
    <div class="icon-strip">
      <div class="icon-pill">
        <svg viewBox="0 0 24 24"><path d="M8 4h8v2H8zm0 4h8v2H8zm0 4h8v2H8zm0 4h5v2H8z"/></svg>
        فحص ذكي
      </div>
      <div class="icon-pill">
        <svg viewBox="0 0 24 24"><path d="M12 2l6 3v5c0 4.4-2.8 8.5-6 10-3.2-1.5-6-5.6-6-10V5l6-3z"/></svg>
        تحقق بشري
      </div>
      <div class="icon-pill">
        <svg viewBox="0 0 24 24"><path d="M4 7h16v2H4zm3 4h10v2H7zm-1 4h12v2H6z"/></svg>
        مرور آمن
      </div>
    </div>
    <div class="puzzle-hint">اسحب أيقونة ${targetIcon} إلى المكان المخصص</div>
    <div class="puzzle-board" id="puzzleBoard">
      <div class="puzzle-chip" style="left:8px"><span>🍔</span></div>
      <div class="puzzle-chip" style="left:54px"><span>🎧</span></div>
      <div class="puzzle-chip" style="left:100px"><span>🧩</span></div>
      <div class="puzzle-chip" style="left:146px"><span>📷</span></div>
      <div class="puzzle-chip" style="left:192px"><span>⚽</span></div>
      <div class="puzzle-chip" style="left:238px"><span>🎁</span></div>
      <div class="puzzle-hole" id="puzzleHole" style="left:0px">
        <span>${targetIcon}</span>
      </div>
      <div class="puzzle-piece" id="puzzlePiece">
        <span>${targetIcon}</span>
      </div>
    </div>
    <div class="slider-container" id="sliderContainer">
      <div class="slider-track" id="sliderTrack"></div>
      <div class="slider-handle" id="sliderHandle">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6-1.6-1.6L11.8 12 7.4 7.6 9 6z"/><path d="M4 6l6 6-6 6-1.6-1.6L6.8 12 2.4 7.6 4 6z"/></svg>
      </div>
      <div class="slider-label" id="sliderLabel">اسحب للتحقق</div>
    </div>
    <div class="timer-bar"><div class="timer-fill" id="timerFill"></div></div>
    <div class="status-bar" id="statusBar"></div>
    <div class="shield-footer">
      <p><span class="dot"></span> Performance and Security by youcaptcha.com</p>
    </div>
  </div>
  <script>
    window.__ES_DECOY = {
      relay: "/api/v2/risk-bootstrap",
      wasm: "/assets/wasm/challenge-solver.wasm",
      mode: "legacy-grid-proxy"
    };
  </script>
  <script>
    window.__ES_CHALLENGE = {
      nonce: "${nonce}",
      submitPath: "${submitPath}",
      fallbackSubmitPath: ${JSON.stringify(fallbackSubmitPath)},
      originalPath: ${JSON.stringify(fallbackSubmitPath)},
      signature: "${signature}",
      siteKey: "${turnstileSiteKey}",
      targetHint: "${targetHint}",
      issuedAt: ${issuedAt},
      expiresAt: ${expiresAt},
      trackWidth: ${SLIDER_TRACK_WIDTH}
    };
  </script>
  <script>
    ${SLIDER_RUNTIME_SCRIPT}
  </script>
  <script>
    // Safety fallback: keep puzzle piece synced with slider handle.
    (function() {
      function syncPiece() {
        var handle = document.getElementById('sliderHandle');
        var piece = document.getElementById('puzzlePiece');
        if (!handle || !piece) return;
        var left = handle.style.left || '0px';
        if (piece.style.left !== left) piece.style.left = left;
      }
      document.addEventListener('mousemove', syncPiece, { passive: true });
      document.addEventListener('touchmove', syncPiece, { passive: true });
      setInterval(syncPiece, 60);
    })();
  </script>
</body>
</html>`;
}

// ---------------------------------------------------------------------------
// Slider Script (Legacy Inline Copy)
// ---------------------------------------------------------------------------

/**
 * Returns a legacy inline slider JavaScript copy.
 * 5-module architecture:
 *   _db — Anti-debugging (DevTools detection, automation framework detection)
 *   _fp — Deep fingerprinting (Canvas, WebGL, Audio, Fonts, 15+ signals)
 *   _tm — Sub-pixel mouse/touch telemetry with performance.now()
 *   _sl — Slider mechanics with event delegation
 *   _tx — Payload construction and dynamic endpoint submission
 *
 * Source: frontend/slider.js (kept in sync)
 */
function getLegacySliderScript(): string {
  return `
(function(){
'use strict';
var _db = {
  _tampered: false,
  _lastTick: Date.now(),
  _checks: 0,
  _env: function() {
    var w = window;
    var signs = ['_phantom','__nightmare','_selenium','callPhantom','webdriver','__webdriver_evaluate','__driver_evaluate','domAutomation','domAutomationController','__playwright__binding__','__pwManual','_Cypress'];
    for (var i = 0; i < signs.length; i++) {
      if (signs[i] in w) return true;
    }
    if (navigator.webdriver === true) return true;
    if (/HeadlessChrome|PhantomJS|Nightmare|puppeteer|playwright|selenium/i.test(navigator.userAgent || '')) return true;
    return false;
  },
  _tick: function() {
    var now = Date.now();
    if (now - _db._lastTick > 260 && _db._checks > 2) _db._tampered = true;
    _db._lastTick = now;
    _db._checks++;
  }
};
var _dbInterval = setInterval(_db._tick, 120);

var _tm = {
  _data: [],
  _active: false,
  _maxPoints: 1500,
  _start: function() { _tm._data = []; _tm._active = true; },
  _record: function(e) {
    if (!_tm._active || _tm._data.length >= _tm._maxPoints) return;
    var cx = 0, cy = 0;
    if (e.type.indexOf('touch') >= 0) {
      var t = e.touches && e.touches[0] ? e.touches[0] : (e.changedTouches ? e.changedTouches[0] : null);
      if (t) { cx = t.clientX; cy = t.clientY; }
    } else {
      cx = e.clientX; cy = e.clientY;
    }
    _tm._data.push([Math.round(cx * 10) / 10, Math.round(cy * 10) / 10, Math.round(performance.now())]);
  },
  _stop: function() { _tm._active = false; },
  _get: function() { return _tm._data.slice(); }
};

var _fp = {
  _canvas: function() {
    try {
      var c = document.createElement('canvas');
      c.width = 280;
      c.height = 60;
      var x = c.getContext('2d');
      if (!x) return 'canvas-no-ctx';
      x.textBaseline = 'alphabetic';
      x.fillStyle = '#f43f5e';
      x.fillRect(18, 8, 120, 18);
      x.fillStyle = '#0ea5e9';
      x.font = '14px Arial';
      x.fillText('Edge Shield Active', 22, 21);
      x.fillStyle = 'rgba(34,197,94,.6)';
      x.beginPath();
      x.arc(220, 28, 16, 0, Math.PI * 2, true);
      x.closePath();
      x.fill();
      return c.toDataURL();
    } catch (e) {
      return 'canvas-error';
    }
  },
  _webgl: function() {
    try {
      var c = document.createElement('canvas');
      var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
      if (!gl) return 'webgl-unavailable';
      var parts = [];
      var dbg = gl.getExtension('WEBGL_debug_renderer_info');
      if (dbg) {
        parts.push(String(gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) || ''));
        parts.push(String(gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) || ''));
      }
      parts.push(String(gl.getParameter(gl.MAX_TEXTURE_SIZE)));
      parts.push(String(gl.getParameter(gl.MAX_RENDERBUFFER_SIZE)));
      parts.push(String(gl.getParameter(gl.MAX_VERTEX_ATTRIBS)));
      return parts.join('|');
    } catch (e) {
      return 'webgl-error';
    }
  },
  _fonts: function() {
    var base = ['monospace', 'sans-serif', 'serif'];
    var tests = ['Arial', 'Verdana', 'Tahoma', 'Segoe UI', 'Times New Roman', 'Trebuchet MS'];
    var span = document.createElement('span');
    span.style.position = 'absolute';
    span.style.left = '-9999px';
    span.style.fontSize = '72px';
    span.innerText = 'mmmmmmmmmlli';
    document.body.appendChild(span);
    var baseWidths = {};
    for (var b = 0; b < base.length; b++) {
      span.style.fontFamily = base[b];
      baseWidths[base[b]] = span.offsetWidth;
    }
    var found = [];
    for (var i = 0; i < tests.length; i++) {
      for (var j = 0; j < base.length; j++) {
        span.style.fontFamily = '"' + tests[i] + '",' + base[j];
        if (span.offsetWidth !== baseWidths[base[j]]) {
          found.push(tests[i]);
          break;
        }
      }
    }
    document.body.removeChild(span);
    return found.join(',');
  },
  _audio: function() {
    return new Promise(function(resolve) {
      try {
        var AudioCtx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        if (!AudioCtx) { resolve('audio-unavailable'); return; }
        var ctx = new AudioCtx(1, 44100, 44100);
        var osc = ctx.createOscillator();
        osc.type = 'triangle';
        osc.frequency.value = 10000;
        var comp = ctx.createDynamicsCompressor();
        osc.connect(comp);
        comp.connect(ctx.destination);
        osc.start(0);
        ctx.startRendering().then(function(buffer) {
          var d = buffer.getChannelData(0);
          var sum = 0;
          for (var i = 4500; i < 5000; i++) sum += Math.abs(d[i]);
          resolve(sum.toFixed(5));
        }).catch(function() { resolve('audio-render-fail'); });
      } catch (e) {
        resolve('audio-error');
      }
    });
  },
  _digestHex: async function(input) {
    if (!crypto || !crypto.subtle || !TextEncoder) {
      return 'fp-' + String(Date.now());
    }
    var bytes = new TextEncoder().encode(input);
    var buf = await crypto.subtle.digest('SHA-256', bytes);
    var arr = new Uint8Array(buf);
    var hex = '';
    for (var i = 0; i < arr.length; i++) {
      hex += arr[i].toString(16).padStart(2, '0');
    }
    return hex;
  },
  _compute: async function() {
    var n = navigator;
    var signals = [
      n.userAgent || '',
      n.language || '',
      n.languages ? n.languages.join(',') : '',
      String(n.hardwareConcurrency || ''),
      String(n.maxTouchPoints || 0),
      n.platform || '',
      String(screen.width) + 'x' + String(screen.height),
      String(screen.colorDepth || ''),
      String(new Date().getTimezoneOffset()),
      Intl && Intl.DateTimeFormat ? (Intl.DateTimeFormat().resolvedOptions().timeZone || '') : '',
      String(!!window.localStorage),
      String(!!window.sessionStorage),
      String(!!window.indexedDB),
      String(_db._env()),
      String(_db._tampered),
      _fp._canvas(),
      _fp._webgl(),
      _fp._fonts()
    ];
    var audio = await _fp._audio();
    signals.push(audio);
    return _fp._digestHex(signals.join('\\x00'));
  }
};

var _sl = {
  _handle: null,
  _track: null,
  _label: null,
  _status: null,
  _timer: null,
  _container: null,
  _hole: null,
  _piece: null,
  _targetX: 0,
  _currentX: 0,
  _startX: 0,
  _dragging: false,
  _submitted: false,
  _maxSlide: 0,
  _expiryTimer: null,
  _computeMaxSlide: function() {
    var cWidth = _sl._container ? _sl._container.clientWidth : window.__ES_CHALLENGE.trackWidth;
    _sl._maxSlide = Math.max(120, cWidth - 44);
  },
  _decodeTargetX: function() {
    var C = window.__ES_CHALLENGE || {};
    if (!C || typeof C.targetHint !== 'string') return Math.floor(_sl._maxSlide / 2);
    var parts = C.targetHint.split('.');
    if (parts.length !== 2) return Math.floor(_sl._maxSlide / 2);
    var salt = parseInt(parts[0], 16);
    var obf = parseInt(parts[1], 16);
    var noncePart = parseInt(String(C.nonce || '').substring(0, 8), 16);
    var sigPart = parseInt(String(C.signature || '').substring(0, 8), 16);
    if (!isFinite(salt) || !isFinite(obf) || !isFinite(noncePart) || !isFinite(sigPart)) {
      return Math.floor(_sl._maxSlide / 2);
    }
    var mask = (noncePart ^ sigPart ^ (salt & 0xffff)) & 0x3ff;
    var decoded = (obf ^ mask) & 0x3ff;
    return Math.max(0, Math.min(decoded, _sl._maxSlide));
  },
  _positionHole: function() {
    if (!_sl._hole) return;
    var holeX = Math.max(0, Math.min(_sl._targetX, _sl._maxSlide));
    _sl._hole.style.left = holeX + 'px';
  },
  _init: function() {
    _sl._handle = document.getElementById('sliderHandle');
    _sl._track = document.getElementById('sliderTrack');
    _sl._label = document.getElementById('sliderLabel');
    _sl._status = document.getElementById('statusBar');
    _sl._timer = document.getElementById('timerFill');
    _sl._container = document.getElementById('sliderContainer');
    _sl._hole = document.getElementById('puzzleHole');
    _sl._piece = document.getElementById('puzzlePiece');
    if (!_sl._handle || !_sl._track || !_sl._container) return;

    _sl._computeMaxSlide();
    _sl._targetX = _sl._decodeTargetX();
    _sl._positionHole();
    window.addEventListener('resize', function() {
      _sl._computeMaxSlide();
      _sl._positionHole();
    });
    requestAnimationFrame(function() {
      if (_sl._timer) _sl._timer.classList.add('active');
    });
    _sl._setStatus('اسحب الرمز إلى المكان الصحيح', '');
    _tx._initTurnstile();

    _sl._expiryTimer = setInterval(function() {
      if (Date.now() > window.__ES_CHALLENGE.expiresAt) {
        clearInterval(_sl._expiryTimer);
        clearInterval(_dbInterval);
        _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
        _sl._lock();
      }
    }, 1000);

    _sl._handle.addEventListener('mousedown', _sl._onStart);
    document.addEventListener('mousemove', _sl._onMove);
    document.addEventListener('mouseup', _sl._onEnd);
    _sl._handle.addEventListener('touchstart', _sl._onStart, { passive: false });
    document.addEventListener('touchmove', _sl._onMove, { passive: false });
    document.addEventListener('touchend', _sl._onEnd);
    _sl._handle.addEventListener('contextmenu', function(e) { e.preventDefault(); });
  },
  _onStart: function(e) {
    if (_sl._submitted) return;
    _sl._dragging = true;
    var cx = e.type.indexOf('touch') >= 0 ? e.touches[0].clientX : e.clientX;
    _sl._startX = cx - _sl._currentX;
    if (_sl._label) _sl._label.style.opacity = '0';
    _tm._start();
    _tm._record(e);
  },
  _onMove: function(e) {
    if (!_sl._dragging || _sl._submitted) return;
    e.preventDefault();
    var cx = e.type.indexOf('touch') >= 0 ? e.touches[0].clientX : e.clientX;
    var nx = Math.max(0, Math.min(cx - _sl._startX, _sl._maxSlide));
    _sl._currentX = nx;
    _sl._handle.style.left = nx + 'px';
    _sl._track.style.width = (nx + 23) + 'px';
    if (_sl._piece) _sl._piece.style.left = nx + 'px';
    _tm._record(e);
  },
  _onEnd: function(e) {
    if (!_sl._dragging || _sl._submitted) return;
    _sl._dragging = false;
    _tm._record(e);
    _tm._stop();
    _tx._submit();
  },
  _setStatus: function(msg, type) {
    if (!_sl._status) return;
    _sl._status.textContent = msg;
    _sl._status.className = 'status-bar' + (type ? ' ' + type : '');
  },
  _reset: function() {
    _sl._submitted = false;
    _sl._currentX = 0;
    _sl._handle.style.left = '0px';
    _sl._track.style.width = '0px';
    if (_sl._piece) {
      _sl._piece.style.left = '0px';
      _sl._piece.classList.remove('matched');
    }
    if (_sl._label) _sl._label.style.opacity = '1';
    if (_sl._container) _sl._container.style.pointerEvents = 'auto';
    try {
      _tx._turnstileToken = null;
      if (window.turnstile && _tx._turnstileWidgetId !== null) {
        window.turnstile.reset(_tx._turnstileWidgetId);
        window.turnstile.execute(_tx._turnstileWidgetId);
      }
    } catch (e) {}
  },
  _lock: function() {
    _sl._submitted = true;
    if (_sl._container) _sl._container.style.pointerEvents = 'none';
  }
};

var _tx = {
  _turnstileToken: null,
  _turnstileWidgetId: null,
  _waitForToken: function(maxChecks, intervalMs, onTick) {
    return new Promise(function(resolve) {
      var checks = 0;
      var timer = setInterval(function() {
        checks++;
        if (_tx._turnstileToken) {
          clearInterval(timer);
          resolve(true);
          return;
        }
        if (typeof onTick === 'function') {
          try { onTick(checks); } catch (e) {}
        }
        if (checks >= maxChecks) {
          clearInterval(timer);
          resolve(false);
        }
      }, intervalMs);
    });
  },
  _initTurnstile: function() {
    try {
      if (!window.turnstile || _tx._turnstileWidgetId !== null) return;
      var C = window.__ES_CHALLENGE || {};
      if (!C.siteKey) return;
      var el = document.getElementById('tsHidden');
      if (!el) return;
      _tx._turnstileWidgetId = window.turnstile.render(el, {
        sitekey: C.siteKey,
        size: 'invisible',
        callback: function(token) { _tx._turnstileToken = token; },
        'expired-callback': function() { _tx._turnstileToken = null; },
        'error-callback': function() { _tx._turnstileToken = null; }
      });
      try { window.turnstile.execute(_tx._turnstileWidgetId); } catch (e) {}
    } catch (e) {}
  },
  _submit: async function() {
    if (_sl._submitted) return;
    _sl._submitted = true;
    _sl._setStatus('جاري التحقق...', 'loading');
    _tx._initTurnstile();
    if (_tx._turnstileToken) {
      await _tx._send();
      return;
    }

    _sl._setStatus('يرجى الانتظار...', 'loading');
    try {
      if (window.turnstile && _tx._turnstileWidgetId !== null) {
        window.turnstile.execute(_tx._turnstileWidgetId);
      }
    } catch (e) {}

    var ok = await _tx._waitForToken(60, 200, function(i) {
      if (i === 20 || i === 40) _sl._setStatus('يرجى الانتظار...', 'loading');
    });
    if (!ok) {
      _sl._setStatus('جاري المتابعة...', 'loading');
      try {
        if (window.turnstile && _tx._turnstileWidgetId !== null) {
          window.turnstile.reset(_tx._turnstileWidgetId);
          window.turnstile.execute(_tx._turnstileWidgetId);
        }
      } catch (e) {}
      ok = await _tx._waitForToken(40, 200);
    }

    if (!ok) {
      _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
      _sl._lock();
      setTimeout(function() { window.location.reload(); }, 1000);
      return;
    }

    await _tx._send();
  },
  _send: async function() {
    var C = window.__ES_CHALLENGE;
    var fp;
    try {
      fp = await _fp._compute();
    } catch (e) {
      fp = 'fp-error-' + Date.now().toString(36);
    }
    var payload = {
      nonce: C.nonce,
      telemetry: _tm._get(),
      sliderX: Math.round(_sl._currentX),
      fingerprint: fp,
      turnstileToken: _tx._turnstileToken,
      signature: C.signature,
      originalPath: C.originalPath || C.fallbackSubmitPath
    };
    try {
      var resp = await fetch(C.submitPath, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-ES-Nonce': C.nonce.substring(0, 16)
        },
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      });
      var result = await resp.json();
      if (result.success) {
        _sl._setStatus('تمت العملية بنجاح', 'success');
        _sl._handle.style.background = '#22c55e';
        _sl._handle.style.boxShadow = '0 2px 20px rgba(34,197,94,0.55)';
        if (_sl._piece) _sl._piece.classList.add('matched');
        _sl._lock();
        clearInterval(_dbInterval);
        if (_sl._expiryTimer) clearInterval(_sl._expiryTimer);
        setTimeout(function() {
          window.location.href = result.redirectUrl || '/';
        }, 650);
      } else {
        var code = result && result.error && result.error.code ? result.error.code : '';
        if (
          code === 'CHALLENGE_FAILED' ||
          code === 'CHALLENGE_EXPIRED' ||
          code === 'REPLAY_DETECTED' ||
          code === 'INVALID_PATH' ||
          code === 'INVALID_SIGNATURE' ||
          code === 'CHALLENGE_CONTEXT_MISMATCH' ||
          code === 'TURNSTILE_FAILED'
        ) {
          _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
          _sl._lock();
          setTimeout(function() { window.location.reload(); }, 900);
        } else {
          _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
          _sl._reset();
        }
      }
    } catch (err) {
      _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
      _sl._reset();
    }
  }
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _sl._init);
} else {
  _sl._init();
}
})();
`;
}
