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
} from "./types";
import {
  generateNonce,
  generateSignature,
  verifySignature,
  createSessionToken,
  hashFingerprint,
} from "./crypto";
import {
  createHtmlResponse,
  createJsonResponse,
  createErrorResponse,
  sanitizeInput,
} from "./utils";

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Slider track width in pixels (must match frontend) */
const SLIDER_TRACK_WIDTH = 300;

/** Minimum valid target X position (pixels from left edge) */
const TARGET_X_MIN = 60;

/** Maximum valid target X position (pixels from left edge) */
const TARGET_X_MAX = 260;

/** Acceptable tolerance for the submitted slider X vs stored target_x (pixels) */
const X_TOLERANCE = 5;

/** Challenge expiration time (seconds) */
const CHALLENGE_TTL_SECONDS = 60;

/** Minimum allowed solve time (milliseconds) — rejects instant bot solves */
const MIN_SOLVE_TIME_MS = 300;

/** Maximum allowed solve time (milliseconds) — rejects stale challenges */
const MAX_SOLVE_TIME_MS = 30000;

/** Minimum telemetry data points required for valid human interaction */
const MIN_TELEMETRY_POINTS = 10;

/** Maximum allowed telemetry data points (prevents payload flooding) */
const MAX_TELEMETRY_POINTS = 2000;

/** Session cookie name (must match index.ts) */
const SESSION_COOKIE_NAME = "es_session";

/** Session token validity (4 hours, in seconds) */
const SESSION_TTL_SECONDS = 4 * 60 * 60;

/** Turnstile verification endpoint */
const TURNSTILE_VERIFY_URL = "https://challenges.cloudflare.com/turnstile/v0/siteverify";

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
  // Generate cryptographic nonce and random target position
  const nonce = generateNonce(32);
  const targetX = generateTargetX();
  const now = Date.now();
  const expiresAt = new Date(now + CHALLENGE_TTL_SECONDS * 1000).toISOString();

  // Build the dynamic submission path (signed with nonce prefix)
  const submitPath = `/es-verify/${nonce.substring(0, 24)}`;

  // Sign the challenge payload for integrity verification
  const signaturePayload = `${nonce}:${submitPath}:${now}`;
  const signature = await generateSignature(signaturePayload, env.JWT_SECRET);

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

  // Build and serve the challenge page
  const challengeHtml = buildChallengeHtml(
    nonce,
    submitPath,
    signature,
    domainConfig.turnstile_sitekey,
    now,
    now + CHALLENGE_TTL_SECONDS * 1000
  );

  return createHtmlResponse(challengeHtml, 403);
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
  env: Env,
  ctx: ExecutionContext
): Promise<Response> {
  // --- 1. Parse and validate request body ---
  let submission: ChallengeSubmission;
  try {
    const body = await request.json();
    submission = validateSubmissionPayload(body);
  } catch (error) {
    return createErrorResponse(
      "INVALID_PAYLOAD",
      "Invalid challenge submission format",
      400
    );
  }

  // --- 2. Verify nonce is unused (KV fast check) ---
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
    return createErrorResponse("REPLAY_DETECTED", "This challenge has already been used", 403);
  }

  // Immediately consume the nonce in KV (one-time use)
  try {
    await env.SESSION_KV.put(nonceKey, "consumed", { expirationTtl: 120 });
  } catch {
    // If KV write fails, the D1 status check below will catch replays
  }

  // --- 3. Retrieve challenge from D1 and verify ---
  let challenge: ChallengeRecord | null;
  try {
    challenge = await env.DB.prepare(
      "SELECT * FROM challenges WHERE nonce = ? AND status = 'pending'"
    )
      .bind(submission.nonce)
      .first<ChallengeRecord>();
  } catch {
    return createErrorResponse("CHALLENGE_ERROR", "Challenge verification failed", 500);
  }

  if (!challenge) {
    return createErrorResponse("CHALLENGE_EXPIRED", "Challenge not found or already used", 403);
  }

  // Check challenge expiration
  if (new Date(challenge.expires_at).getTime() < Date.now()) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "expired"));
    return createErrorResponse("CHALLENGE_EXPIRED", "Challenge has expired", 403);
  }

  // Verify IP matches the original challenge request
  if (challenge.ip_address !== meta.ip) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "ip_mismatch"));
    ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
      `IP mismatch: challenge issued to ${challenge.ip_address}`));
    return createErrorResponse("IP_MISMATCH", "Challenge IP mismatch", 403);
  }

  // --- 4. Verify dynamic submit path signature ---
  const pathNoncePrefix = meta.path.split("/es-verify/")[1];
  if (!pathNoncePrefix || !submission.nonce.startsWith(pathNoncePrefix)) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "path_mismatch"));
    return createErrorResponse("INVALID_PATH", "Invalid submission path", 403);
  }

  // --- 5. Verify Turnstile token ---
  const turnstileValid = await verifyTurnstile(
    submission.turnstileToken,
    meta.ip,
    domainConfig.turnstile_secret
  );
  if (!turnstileValid) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "turnstile_failed"));
    ctx.waitUntil(logEvent(env, "turnstile_failed", meta, submission.fingerprint,
      "Turnstile verification failed"));
    return createErrorResponse("TURNSTILE_FAILED", "Turnstile verification failed", 403);
  }

  // --- 6. Analyze telemetry for human behavior ---
  const telemetryResult = analyzeTelemetry(submission.telemetry);
  if (!telemetryResult.isHuman) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "telemetry_rejected"));
    ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
      `Telemetry rejected: ${telemetryResult.reason}`));
    ctx.waitUntil(updateFingerprintFailure(env, submission.fingerprint, meta));
    return createErrorResponse("CHALLENGE_FAILED", "Verification failed", 403);
  }

  // --- 7. Verify slider X position matches target ---
  const xDiff = Math.abs(submission.sliderX - challenge.target_x);
  if (xDiff > X_TOLERANCE) {
    ctx.waitUntil(markChallengeFailed(env, challenge.nonce, "x_mismatch"));
    ctx.waitUntil(logEvent(env, "challenge_failed", meta, submission.fingerprint,
      `X position mismatch: submitted ${submission.sliderX}, target ${challenge.target_x}, diff ${xDiff}`));
    ctx.waitUntil(updateFingerprintFailure(env, submission.fingerprint, meta));
    return createErrorResponse("CHALLENGE_FAILED", "Verification failed", 403);
  }

  // ========= CHALLENGE PASSED — Issue Session Token =========

  // Mark challenge as solved in D1
  ctx.waitUntil(
    env.DB.prepare("UPDATE challenges SET status = 'solved', solved_at = CURRENT_TIMESTAMP WHERE nonce = ?")
      .bind(challenge.nonce).run().catch(() => {})
  );

  // Update fingerprint record
  ctx.waitUntil(upsertFingerprint(env, submission.fingerprint, meta));

  // Generate session token (JWT bound to IP + fingerprint)
  const now = Math.floor(Date.now() / 1000);
  const claims: SessionTokenClaims = {
    sub: submission.fingerprint,
    iss: "edge-shield",
    iat: now,
    exp: now + SESSION_TTL_SECONDS,
    ip: meta.ip,
    fph: submission.fingerprint,
    rsk: telemetryResult.humanScore,
  };

  const sessionToken = await createSessionToken(claims, env.JWT_SECRET);

  // Log successful challenge
  ctx.waitUntil(logEvent(env, "challenge_solved", meta, submission.fingerprint,
    `Solved in ${telemetryResult.solveTimeMs}ms, human score: ${telemetryResult.humanScore}`));

  // Return success with session cookie
  const cookieHeader = buildSessionCookie(sessionToken, SESSION_TTL_SECONDS);

  return createJsonResponse(
    {
      success: true,
      message: "Verification complete",
      redirectUrl: "/",
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
  telemetry: Array<[number, number, number]>
): TelemetryAnalysisResult {
  // --- Check 1: Sufficient data points ---
  if (!telemetry || telemetry.length < MIN_TELEMETRY_POINTS) {
    return {
      isHuman: false,
      humanScore: 0,
      reason: `Insufficient telemetry: ${telemetry?.length || 0} points (min: ${MIN_TELEMETRY_POINTS})`,
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

  if (solveTimeMs < MIN_SOLVE_TIME_MS) {
    return {
      isHuman: false,
      humanScore: 0,
      reason: `Solve too fast: ${solveTimeMs}ms (min: ${MIN_SOLVE_TIME_MS}ms)`,
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
  // Bots often produce perfectly straight horizontal lines (all Y values identical)
  const ySet = new Set(yPositions);
  if (ySet.size <= 2) {
    return {
      isHuman: false,
      humanScore: 5,
      reason: "Perfectly straight line detected (Y deviation = 0)",
      solveTimeMs,
    };
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
 * Verifies a Turnstile token server-side via the Cloudflare API.
 * Returns true if the token is valid, false otherwise.
 */
async function verifyTurnstile(
  token: string,
  clientIP: string,
  secret: string
): Promise<boolean> {
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

    const result = await response.json() as { success: boolean };
    return result.success === true;
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
  for (const field of ["nonce", "fingerprint", "turnstileToken", "signature"]) {
    if (typeof obj[field] !== "string" || (obj[field] as string).length === 0) {
      throw new Error(`Missing or invalid field: ${field}`);
    }
  }

  // sliderX must be a finite number
  if (typeof obj.sliderX !== "number" || !isFinite(obj.sliderX)) {
    throw new Error("sliderX must be a finite number");
  }

  // telemetry must be an array of [number, number, number] tuples
  if (!Array.isArray(obj.telemetry)) {
    throw new Error("telemetry must be an array");
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
    turnstileToken: obj.turnstileToken as string,
    signature: obj.signature as string,
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

// ---------------------------------------------------------------------------
// Helper: Session Cookie Builder
// ---------------------------------------------------------------------------

/**
 * Builds a secure session cookie string.
 * Attributes: Secure, HttpOnly, SameSite=Lax, Path=/, Max-Age
 */
function buildSessionCookie(token: string, maxAge: number): string {
  return [
    `${SESSION_COOKIE_NAME}=${token}`,
    `Max-Age=${maxAge}`,
    "Path=/",
    "HttpOnly",
    "Secure",
    "SameSite=Lax",
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
  try {
    await env.DB.prepare(
      `INSERT INTO security_logs (event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
       VALUES (?, ?, ?, ?, ?, ?, NULL, ?)`
    )
      .bind(eventType, meta.ip, meta.asn, meta.country, meta.path, fingerprintHash, details)
      .run();
  } catch {
    // Non-fatal
  }
}

// ---------------------------------------------------------------------------
// Challenge HTML Page Builder (Phase 6: Enhanced UI + Obfuscated Script)
// ---------------------------------------------------------------------------

/**
 * Builds the production CAPTCHA challenge HTML page.
 * Features:
 *   - Glassmorphism dark UI with animated grid background
 *   - Embedded obfuscated slider.js (5-module fingerprint engine)
 *   - Cloudflare Turnstile widget (invisible, dark mode)
 *   - Dynamic submission endpoint
 *   - Challenge metadata (nonce, signature, expiry)
 *
 * The target_x is NEVER included — it stays server-side only.
 */
function buildChallengeHtml(
  nonce: string,
  submitPath: string,
  signature: string,
  turnstileSiteKey: string,
  issuedAt: number,
  expiresAt: number
): string {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Security Verification</title>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --bg-primary: #050a14;
      --bg-card: rgba(15, 23, 42, 0.85);
      --bg-card-border: rgba(56, 97, 251, 0.12);
      --text-primary: #e2e8f0;
      --text-secondary: #8892a8;
      --text-muted: #4a5568;
      --accent: #3b82f6;
      --accent-bright: #60a5fa;
      --success: #22c55e;
      --error: #ef4444;
      --border: rgba(255, 255, 255, 0.06);
      --slider-track-bg: rgba(30, 41, 59, 0.8);
      --slider-handle: linear-gradient(135deg, #3b82f6, #2563eb);
      --slider-fill: linear-gradient(90deg, rgba(59, 130, 246, 0.6), rgba(59, 130, 246, 0.2));
    }
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
      background: var(--bg-primary);
      color: var(--text-primary);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      overflow: hidden;
    }
    body::before {
      content: '';
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background:
        linear-gradient(90deg, rgba(59, 130, 246, 0.03) 1px, transparent 1px),
        linear-gradient(rgba(59, 130, 246, 0.03) 1px, transparent 1px);
      background-size: 40px 40px;
      pointer-events: none;
      z-index: 0;
    }
    body::after {
      content: '';
      position: fixed;
      width: 500px; height: 500px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(59, 130, 246, 0.08), transparent 70%);
      top: -150px; right: -100px;
      pointer-events: none;
      z-index: 0;
      animation: float 8s ease-in-out infinite alternate;
    }
    @keyframes float {
      0% { transform: translate(0, 0) scale(1); }
      100% { transform: translate(-30px, 30px) scale(1.1); }
    }
    .shield-container {
      position: relative;
      z-index: 1;
      background: var(--bg-card);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid var(--bg-card-border);
      border-radius: 20px;
      padding: 2.5rem 2rem;
      max-width: 420px;
      width: 100%;
      box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.02),
        0 0 80px rgba(59, 130, 246, 0.06),
        0 30px 60px rgba(0, 0, 0, 0.5);
      animation: cardIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
    }
    @keyframes cardIn {
      from { opacity: 0; transform: translateY(30px) scale(0.96); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .shield-header { text-align: center; margin-bottom: 2rem; }
    .shield-icon {
      width: 56px; height: 56px;
      margin: 0 auto 1.25rem;
      background: linear-gradient(135deg, #3b82f6, #8b5cf6);
      border-radius: 16px;
      display: flex; align-items: center; justify-content: center;
      font-size: 1.75rem;
      box-shadow: 0 8px 32px rgba(59, 130, 246, 0.25);
      animation: iconPulse 2s ease-in-out infinite alternate;
    }
    @keyframes iconPulse {
      0% { box-shadow: 0 8px 32px rgba(59, 130, 246, 0.2); }
      100% { box-shadow: 0 8px 48px rgba(59, 130, 246, 0.35); }
    }
    .shield-header h1 {
      font-size: 1.3rem; font-weight: 700; margin-bottom: 0.5rem; letter-spacing: -0.01em;
      background: linear-gradient(135deg, #fff, #94a3b8);
      -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .shield-header p { font-size: 0.875rem; color: var(--text-secondary); line-height: 1.5; }
    .slider-container {
      position: relative; width: 300px; height: 52px; margin: 1.5rem auto;
      background: var(--slider-track-bg); border-radius: 26px;
      border: 1px solid var(--border); overflow: hidden;
      user-select: none; -webkit-user-select: none; touch-action: none;
    }
    .slider-track {
      position: absolute; top: 0; left: 0; height: 100%;
      background: var(--slider-fill); border-radius: 26px 0 0 26px;
      width: 0; transition: none;
    }
    .slider-handle {
      position: absolute; top: 4px; left: 0; width: 44px; height: 44px;
      background: var(--slider-handle); border-radius: 50%;
      cursor: grab; display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4), 0 0 0 3px rgba(59, 130, 246, 0.1);
      z-index: 10; transition: box-shadow 0.2s ease, background 0.3s ease;
    }
    .slider-handle:hover {
      box-shadow: 0 2px 16px rgba(59, 130, 246, 0.5), 0 0 0 4px rgba(59, 130, 246, 0.15);
    }
    .slider-handle:active {
      cursor: grabbing;
      box-shadow: 0 2px 24px rgba(59, 130, 246, 0.6), 0 0 0 6px rgba(59, 130, 246, 0.2);
    }
    .slider-handle svg { width: 18px; height: 18px; fill: white; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3)); }
    .slider-label {
      position: absolute; top: 50%; left: 55%; transform: translate(-50%, -50%);
      font-size: 0.75rem; font-weight: 500; color: var(--text-muted);
      pointer-events: none; letter-spacing: 1px; text-transform: uppercase;
      transition: opacity 0.2s ease;
    }
    .timer-bar { width: 100%; height: 3px; background: rgba(255,255,255,0.04); border-radius: 2px; margin-top: 1rem; overflow: hidden; }
    .timer-fill {
      height: 100%; background: linear-gradient(90deg, var(--accent), var(--accent-bright));
      border-radius: 2px; width: 100%;
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
    .turnstile-wrapper { display: flex; justify-content: center; margin-top: 1.25rem; }
    .shield-footer { text-align: center; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border); }
    .shield-footer p {
      font-size: 0.7rem; color: var(--text-muted);
      display: flex; align-items: center; justify-content: center; gap: 0.4rem;
    }
    .shield-footer .dot {
      display: inline-block; width: 6px; height: 6px; border-radius: 50%;
      background: var(--accent); animation: dotPulse 2s ease-in-out infinite;
    }
    @keyframes dotPulse {
      0%, 100% { opacity: 0.4; transform: scale(0.8); }
      50% { opacity: 1; transform: scale(1.2); }
    }
    @media (max-width: 440px) {
      .shield-container { padding: 2rem 1.5rem; border-radius: 16px; }
      .slider-container { width: 260px; }
    }
  </style>
</head>
<body>
  <div class="shield-container">
    <div class="shield-header">
      <div class="shield-icon">\u{1F6E1}\u{FE0F}</div>
      <h1>Security Verification</h1>
      <p>Slide to verify you are human</p>
    </div>
    <div class="slider-container" id="sliderContainer">
      <div class="slider-track" id="sliderTrack"></div>
      <div class="slider-handle" id="sliderHandle">
        <svg viewBox="0 0 24 24"><path d="M5 3l14 9-14 9V3z"/></svg>
      </div>
      <div class="slider-label" id="sliderLabel">Slide \u2192</div>
    </div>
    <div class="timer-bar"><div class="timer-fill" id="timerFill"></div></div>
    <div class="status-bar" id="statusBar"></div>
    <div class="turnstile-wrapper">
      <div class="cf-turnstile" data-sitekey="${turnstileSiteKey}" data-callback="onTurnstileSuccess" data-theme="dark" data-size="compact"></div>
    </div>
    <div class="shield-footer">
      <p><span class="dot"></span> Protected by Edge Shield</p>
    </div>
  </div>
  <script>
    window.__ES_CHALLENGE = {
      nonce: "${nonce}",
      submitPath: "${submitPath}",
      signature: "${signature}",
      issuedAt: ${issuedAt},
      expiresAt: ${expiresAt},
      trackWidth: ${SLIDER_TRACK_WIDTH}
    };
  </script>
  <script>
    ${getObfuscatedSliderScript()}
  </script>
</body>
</html>`;
}

// ---------------------------------------------------------------------------
// Obfuscated Slider Script (Phase 6)
// ---------------------------------------------------------------------------

/**
 * Returns the production-grade obfuscated slider JavaScript.
 * 5-module architecture:
 *   _db — Anti-debugging (DevTools detection, automation framework detection)
 *   _fp — Deep fingerprinting (Canvas, WebGL, Audio, Fonts, 15+ signals)
 *   _tm — Sub-pixel mouse/touch telemetry with performance.now()
 *   _sl — Slider mechanics with event delegation
 *   _tx — Payload construction and dynamic endpoint submission
 *
 * Source: frontend/slider.js (kept in sync)
 */
function getObfuscatedSliderScript(): string {
  return `
(function(){
'use strict';
var _db={_t0:Date.now(),_checks:0,_tick:function(){var _n=Date.now();if(_n-_db._t0>200&&_db._checks>2){_db._tampered=true;}_db._t0=_n;_db._checks++;},_probe:function(){var _s=performance.now();try{console.log.apply(null);}catch(e){}return(performance.now()-_s)>50;},_tampered:false,_env:function(){var _w=window;var _signs=['_phantom','__nightmare','_selenium','callPhantom','webdriver','__webdriver_evaluate','__driver_evaluate','domAutomation','domAutomationController','_Recaptcha','__coverage__'];for(var i=0;i<_signs.length;i++){if(_signs[i] in _w)return true;}if(navigator.webdriver===true)return true;if(/HeadlessChrome|PhantomJS|Nightmare/i.test(navigator.userAgent))return true;return false;}};
var _dbInterval=setInterval(_db._tick,100);
var _fp={_nav:function(){var n=navigator;return[n.userAgent||'',n.language||'',n.languages?n.languages.join(','):'',String(n.hardwareConcurrency||''),String(n.maxTouchPoints||0),n.platform||'',String(screen.width)+'x'+String(screen.height),String(screen.availWidth)+'x'+String(screen.availHeight),String(screen.colorDepth),String(screen.pixelDepth||''),String(new Date().getTimezoneOffset()),Intl&&Intl.DateTimeFormat?Intl.DateTimeFormat().resolvedOptions().timeZone:'',String(!!window.sessionStorage),String(!!window.localStorage),String(!!window.indexedDB),String(!!window.openDatabase)];},_canvas:function(){try{var c=document.createElement('canvas');c.width=280;c.height=60;var x=c.getContext('2d');if(!x)return'canvas-no-ctx';x.textBaseline='alphabetic';x.fillStyle='#f60';x.fillRect(125,1,62,20);x.fillStyle='#069';x.font='11pt no-real-font-123';x.fillText('Cwm fjord veg balks',2,15);x.fillStyle='rgba(102,204,0,0.7)';x.font='18pt Arial';x.fillText('EdgeShield\\ud83d\\udee1\\ufe0f',4,45);x.globalCompositeOperation='multiply';x.fillStyle='rgb(255,0,255)';x.beginPath();x.arc(50,50,50,0,Math.PI*2,true);x.closePath();x.fill();x.fillStyle='rgb(0,255,255)';x.beginPath();x.arc(100,50,50,0,Math.PI*2,true);x.closePath();x.fill();return c.toDataURL();}catch(e){return'canvas-error';}},_webgl:function(){try{var c=document.createElement('canvas');var gl=c.getContext('webgl')||c.getContext('experimental-webgl');if(!gl)return'webgl-unavailable';var parts=[];var dbg=gl.getExtension('WEBGL_debug_renderer_info');if(dbg){parts.push(gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL));parts.push(gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL));}parts.push(String(gl.getParameter(gl.MAX_VERTEX_ATTRIBS)));parts.push(String(gl.getParameter(gl.MAX_VARYING_VECTORS)));parts.push(String(gl.getParameter(gl.MAX_VERTEX_UNIFORM_VECTORS)));parts.push(String(gl.getParameter(gl.MAX_FRAGMENT_UNIFORM_VECTORS)));parts.push(String(gl.getParameter(gl.MAX_TEXTURE_SIZE)));parts.push(String(gl.getParameter(gl.MAX_RENDERBUFFER_SIZE)));parts.push(String(gl.getParameter(gl.ALIASED_LINE_WIDTH_RANGE)));parts.push(String(gl.getParameter(gl.ALIASED_POINT_SIZE_RANGE)));var exts=gl.getSupportedExtensions();if(exts)parts.push(exts.sort().join(','));try{var hp=gl.getShaderPrecisionFormat(gl.FRAGMENT_SHADER,gl.HIGH_FLOAT);if(hp)parts.push(hp.precision+':'+hp.rangeMin+':'+hp.rangeMax);}catch(e){}return parts.join('|');}catch(e){return'webgl-error';}},_audio:function(){return new Promise(function(resolve){try{var AudioCtx=window.OfflineAudioContext||window.webkitOfflineAudioContext;if(!AudioCtx){resolve('audio-unavailable');return;}var ctx=new AudioCtx(1,44100,44100);var osc=ctx.createOscillator();osc.type='triangle';osc.frequency.setValueAtTime(10000,ctx.currentTime);var comp=ctx.createDynamicsCompressor();comp.threshold.setValueAtTime(-50,ctx.currentTime);comp.knee.setValueAtTime(40,ctx.currentTime);comp.ratio.setValueAtTime(12,ctx.currentTime);comp.attack.setValueAtTime(0,ctx.currentTime);comp.release.setValueAtTime(0.25,ctx.currentTime);osc.connect(comp);comp.connect(ctx.destination);osc.start(0);ctx.startRendering().then(function(buffer){var data=buffer.getChannelData(0);var sum=0;for(var i=4500;i<5000;i++){sum+=Math.abs(data[i]);}resolve(sum.toString());}).catch(function(){resolve('audio-render-fail');});setTimeout(function(){resolve('audio-timeout');},1000);}catch(e){resolve('audio-error');}});},_fonts:function(){var baseFonts=['monospace','sans-serif','serif'];var testFonts=['Arial','Arial Black','Comic Sans MS','Courier New','Georgia','Impact','Lucida Console','Palatino Linotype','Tahoma','Times New Roman','Trebuchet MS','Verdana','Lucida Sans Unicode','Microsoft Sans Serif','Segoe UI'];var testStr='mmmmmmmmmmlli';var testSize='72px';var detected=[];var span=document.createElement('span');span.style.position='absolute';span.style.left='-9999px';span.style.fontSize=testSize;span.innerText=testStr;document.body.appendChild(span);var baseWidths={};for(var b=0;b<baseFonts.length;b++){span.style.fontFamily=baseFonts[b];baseWidths[baseFonts[b]]=span.offsetWidth;}for(var f=0;f<testFonts.length;f++){for(var j=0;j<baseFonts.length;j++){span.style.fontFamily='"'+testFonts[f]+'",'+baseFonts[j];if(span.offsetWidth!==baseWidths[baseFonts[j]]){detected.push(testFonts[f]);break;}}}document.body.removeChild(span);return detected.join(',');},_compute:async function(){var signals=_fp._nav();signals.push(_fp._canvas());signals.push(_fp._webgl());signals.push(_fp._fonts());var audioFp=await _fp._audio();signals.push(audioFp);signals.push(String(_db._env()));signals.push(String(_db._tampered));return _fp._hash(signals);},_hash:function(arr){var str=arr.join('\\x00');var h1=0x811c9dc5>>>0;var h2=0x1000193>>>0;for(var i=0;i<str.length;i++){h1^=str.charCodeAt(i);h1=Math.imul(h1,h2)>>>0;}var hex=(h1>>>0).toString(16).padStart(8,'0');return hex+str.length.toString(36)+(Date.now()%0xFFFF).toString(16);}};
var _tm={_data:[],_active:false,_maxPoints:1500,_start:function(){_tm._data=[];_tm._active=true;},_record:function(e){if(!_tm._active)return;if(_tm._data.length>=_tm._maxPoints)return;var cx=0,cy=0;if(e.type.indexOf('touch')>=0){var t=e.touches&&e.touches[0]?e.touches[0]:(e.changedTouches?e.changedTouches[0]:null);if(t){cx=t.clientX;cy=t.clientY;}}else{cx=e.clientX;cy=e.clientY;}_tm._data.push([Math.round(cx*10)/10,Math.round(cy*10)/10,performance.now()|0]);},_stop:function(){_tm._active=false;},_get:function(){return _tm._data.slice();}};
var _sl={_handle:null,_track:null,_label:null,_status:null,_timer:null,_container:null,_maxSlide:0,_currentX:0,_startX:0,_dragging:false,_submitted:false,_init:function(){_sl._handle=document.getElementById('sliderHandle');_sl._track=document.getElementById('sliderTrack');_sl._label=document.getElementById('sliderLabel');_sl._status=document.getElementById('statusBar');_sl._timer=document.getElementById('timerFill');_sl._container=document.getElementById('sliderContainer');_sl._maxSlide=window.__ES_CHALLENGE.trackWidth-44;requestAnimationFrame(function(){if(_sl._timer)_sl._timer.classList.add('active');});var _ex=setInterval(function(){if(Date.now()>window.__ES_CHALLENGE.expiresAt){clearInterval(_ex);clearInterval(_dbInterval);_sl._setStatus('Challenge expired. Please refresh the page.','error');if(_sl._container)_sl._container.style.pointerEvents='none';}},1000);_sl._handle.addEventListener('mousedown',_sl._onStart);document.addEventListener('mousemove',_sl._onMove);document.addEventListener('mouseup',_sl._onEnd);_sl._handle.addEventListener('touchstart',_sl._onStart,{passive:false});document.addEventListener('touchmove',_sl._onMove,{passive:false});document.addEventListener('touchend',_sl._onEnd);_sl._handle.addEventListener('contextmenu',function(e){e.preventDefault();});},_onStart:function(e){if(_sl._submitted)return;_sl._dragging=true;var cx=e.type.indexOf('touch')>=0?e.touches[0].clientX:e.clientX;_sl._startX=cx-_sl._currentX;if(_sl._label)_sl._label.style.opacity='0';_tm._start();_tm._record(e);},_onMove:function(e){if(!_sl._dragging||_sl._submitted)return;e.preventDefault();var cx=e.type.indexOf('touch')>=0?e.touches[0].clientX:e.clientX;var nx=Math.max(0,Math.min(cx-_sl._startX,_sl._maxSlide));_sl._currentX=nx;_sl._handle.style.left=nx+'px';_sl._track.style.width=(nx+22)+'px';_tm._record(e);},_onEnd:function(e){if(!_sl._dragging||_sl._submitted)return;_sl._dragging=false;_tm._record(e);_tm._stop();_tx._submit();},_setStatus:function(msg,type){if(_sl._status){_sl._status.textContent=msg;_sl._status.className='status-bar'+(type?' '+type:'');}},_reset:function(){_sl._submitted=false;_sl._currentX=0;_sl._handle.style.left='0px';_sl._track.style.width='0px';if(_sl._label)_sl._label.style.opacity='1';},_lock:function(){_sl._submitted=true;if(_sl._container)_sl._container.style.pointerEvents='none';}};
var _tx={_turnstileToken:null,_submit:async function(){if(_sl._submitted)return;_sl._submitted=true;_sl._setStatus('Verifying...','loading');if(!_tx._turnstileToken){_sl._setStatus('Completing security check...','loading');var _wc=0;var _wi=setInterval(function(){_wc++;if(_tx._turnstileToken||_wc>30){clearInterval(_wi);if(_tx._turnstileToken){_tx._send();}else{_sl._setStatus('Security check timed out. Please refresh.','error');_sl._reset();}}},200);return;}await _tx._send();},_send:async function(){var C=window.__ES_CHALLENGE;var fp;try{fp=await _fp._compute();}catch(e){fp='fp-error-'+Date.now().toString(36);}var telemetry=_tm._get();var payload={nonce:C.nonce,telemetry:telemetry,sliderX:Math.round(_sl._currentX),fingerprint:fp,turnstileToken:_tx._turnstileToken,signature:C.signature};try{var resp=await fetch(C.submitPath,{method:'POST',headers:{'Content-Type':'application/json','X-ES-Nonce':C.nonce.substring(0,16)},body:JSON.stringify(payload),credentials:'same-origin'});var result=await resp.json();if(result.success){_sl._setStatus('\\u2713 Verified successfully','success');_sl._handle.style.background='#22c55e';_sl._handle.style.boxShadow='0 2px 20px rgba(34,197,94,0.6)';_sl._lock();clearInterval(_dbInterval);setTimeout(function(){window.location.href=result.redirectUrl||'/';},700);}else{_sl._setStatus('Verification failed. Please try again.','error');_sl._reset();}}catch(err){_sl._setStatus('Network error. Please retry.','error');_sl._reset();}}};
window.onTurnstileSuccess=function(token){_tx._turnstileToken=token;};
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',_sl._init);}else{_sl._init();}
})();
`;
}


