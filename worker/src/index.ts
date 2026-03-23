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
  sanitizeInput,
  isValidIP,
} from "./utils";
import {
  evaluateRisk,
  incrementIPRate,
  incrementASNRate,
  incrementSubnetRate,
  incrementPathRate,
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
const TEMP_BAN_TTL_SECONDS = 24 * 60 * 60;
const ADMIN_ALLOW_PREFIX = "allow:ip:";
const ADMIN_ALLOW_DEFAULT_TTL_SECONDS = 24 * 60 * 60;
const ADMIN_RATE_PREFIX = "rate:admin:";
const ADMIN_RATE_WINDOW_SECONDS = 60;
const ADMIN_RATE_DEFAULT_PER_MIN = 60;
const AI_MODEL_SETTING_KEY = "settings:ai:model";
const AI_MODEL_FALLBACKS_SETTING_KEY = "settings:ai:fallbacks";
const IP_HARD_BAN_RATE_THRESHOLD = 120; // requests/minute per IP
const CRAWLER_RDNS_CACHE_PREFIX = "crawler:rdns:";
const CRAWLER_RDNS_OK_TTL_SECONDS = 24 * 60 * 60;
const CRAWLER_RDNS_FAIL_TTL_SECONDS = 60 * 60;
const KNOWN_CRAWLER_UA_PATTERN =
  /(googlebot|google-inspectiontool|googleother|adsbot-google|mediapartners-google|bingbot|adidxbot|duckduckbot|yandexbot|baiduspider|applebot|slurp|amazonbot|facebookexternalhit|linkedinbot|twitterbot|petalbot|semrushbot|ahrefsbot|mj12bot|dotbot|seznambot|ccbot)/i;
type CrawlerFamily = "google" | "bing" | "amazon" | "duckduckgo" | "apple" | "yandex" | "baidu";
const CRAWLER_RDNS_SUFFIXES: Record<CrawlerFamily, string[]> = {
  google: [".googlebot.com", ".google.com"],
  bing: [".search.msn.com"],
  amazon: [".amazonbot.amazon"],
  duckduckgo: [".duckduckgo.com"],
  apple: [".applebot.apple.com"],
  yandex: [".yandex.ru", ".yandex.net"],
  baidu: [".baidu.com"],
};
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
 * Extracts a signed, non-expired fingerprint hint from the session token.
 * Unlike validateSession(), this does not enforce IP binding. It is used only
 * as a risk-correlation hint to improve anomaly detection accuracy.
 */
async function getSessionFingerprintHint(
  request: Request,
  env: Env
): Promise<string | null> {
  const cookieHeader = request.headers.get("Cookie");
  if (!cookieHeader) return null;

  const sessionToken = parseCookieValue(cookieHeader, SESSION_COOKIE_NAME);
  if (!sessionToken) return null;

  const claims = await verifySessionToken(sessionToken, env.JWT_SECRET);
  if (!claims?.sub) return null;
  if (!/^[a-f0-9]{64}$/i.test(claims.sub)) return null;
  return claims.sub.toLowerCase();
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
  const domainName = extractDomainFromMeta(meta);
  try {
    await env.DB.prepare(
      `INSERT INTO security_logs (domain_name, event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`
    )
      .bind(
        domainName,
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

function extractDomainFromMeta(meta: RequestMeta): string | null {
  try {
    const host = new URL(meta.url).hostname.trim().toLowerCase();
    return host === "" ? null : host;
  } catch {
    return null;
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

async function isAdminAllowedIP(ip: string, env: Env): Promise<boolean> {
  try {
    const allow = await env.SESSION_KV.get(`${ADMIN_ALLOW_PREFIX}${ip}`);
    return allow !== null;
  } catch {
    return false;
  }
}

function isIPv4(ip: string): boolean {
  return /^(\d{1,3}\.){3}\d{1,3}$/.test(ip);
}

function toIPv4PtrDomain(ip: string): string | null {
  if (!isIPv4(ip)) return null;
  const octets = ip.split(".");
  if (octets.length !== 4) return null;
  return `${octets[3]}.${octets[2]}.${octets[1]}.${octets[0]}.in-addr.arpa`;
}

function normalizeDnsName(value: string): string {
  return value.trim().toLowerCase().replace(/\.$/, "");
}

function detectCrawlerFamily(userAgent: string): CrawlerFamily | null {
  const ua = (userAgent || "").toLowerCase();
  if (/(googlebot|google-inspectiontool|googleother|adsbot-google|mediapartners-google)/i.test(ua)) {
    return "google";
  }
  if (/(bingbot|adidxbot)/i.test(ua)) return "bing";
  if (/amazonbot/i.test(ua)) return "amazon";
  if (/duckduckbot/i.test(ua)) return "duckduckgo";
  if (/applebot/i.test(ua)) return "apple";
  if (/yandexbot/i.test(ua)) return "yandex";
  if (/baiduspider/i.test(ua)) return "baidu";
  return null;
}

function matchesCrawlerHostname(hostname: string, family: CrawlerFamily): boolean {
  const normalized = normalizeDnsName(hostname);
  const suffixes = CRAWLER_RDNS_SUFFIXES[family] || [];
  for (const suffix of suffixes) {
    if (normalized.endsWith(suffix)) return true;
  }
  return false;
}

async function queryDnsRecords(
  name: string,
  type: "PTR" | "A"
): Promise<string[]> {
  const url = `https://cloudflare-dns.com/dns-query?name=${encodeURIComponent(name)}&type=${type}`;
  const response = await fetch(url, {
    headers: { Accept: "application/dns-json" },
  });
  if (!response.ok) return [];
  const payload = (await response.json()) as { Answer?: Array<{ data?: string }> };
  const answers = Array.isArray(payload?.Answer) ? payload.Answer : [];
  return answers
    .map((record) => String(record?.data || "").trim())
    .filter(Boolean)
    .map((record) => normalizeDnsName(record));
}

async function verifyCrawlerByReverseDns(
  ip: string,
  family: CrawlerFamily,
  env: Env
): Promise<boolean> {
  const cacheKey = `${CRAWLER_RDNS_CACHE_PREFIX}${family}:${ip}`;
  try {
    const cached = await env.SESSION_KV.get(cacheKey);
    if (cached === "1") return true;
    if (cached === "0") return false;
  } catch {
    // Continue without cache
  }

  const ptrDomain = toIPv4PtrDomain(ip);
  if (!ptrDomain) return false;

  let verified = false;
  try {
    const ptrHosts = await queryDnsRecords(ptrDomain, "PTR");
    for (const ptrHost of ptrHosts) {
      if (!matchesCrawlerHostname(ptrHost, family)) continue;
      const forwardIPs = await queryDnsRecords(ptrHost, "A");
      if (forwardIPs.includes(ip)) {
        verified = true;
        break;
      }
    }
  } catch {
    verified = false;
  }

  try {
    await env.SESSION_KV.put(cacheKey, verified ? "1" : "0", {
      expirationTtl: verified
        ? CRAWLER_RDNS_OK_TTL_SECONDS
        : CRAWLER_RDNS_FAIL_TTL_SECONDS,
    });
  } catch {
    // Cache write failures are non-fatal
  }

  return verified;
}

function ipv4ToInt(ip: string): number | null {
  if (!isIPv4(ip)) return null;
  const octets = ip.split(".").map((o) => parseInt(o, 10));
  if (octets.length !== 4 || octets.some((o) => Number.isNaN(o) || o < 0 || o > 255)) {
    return null;
  }
  return (
    ((octets[0] << 24) >>> 0) +
    ((octets[1] << 16) >>> 0) +
    ((octets[2] << 8) >>> 0) +
    (octets[3] >>> 0)
  ) >>> 0;
}

function isIPv4InCidr(ip: string, cidr: string): boolean {
  const [base, maskText] = cidr.split("/");
  if (!base || !maskText) return false;
  const maskBits = parseInt(maskText, 10);
  if (!Number.isFinite(maskBits) || maskBits < 0 || maskBits > 32) return false;

  const ipInt = ipv4ToInt(ip);
  const baseInt = ipv4ToInt(base);
  if (ipInt === null || baseInt === null) return false;

  if (maskBits === 0) return true;
  const mask = (0xffffffff << (32 - maskBits)) >>> 0;
  return (ipInt & mask) === (baseInt & mask);
}

function isAdminSourceAllowed(ip: string, env: Env): boolean {
  const rawAllowlist = (env.ES_ADMIN_ALLOWED_IPS || "").trim();
  if (!rawAllowlist) return true;

  const candidates = rawAllowlist
    .split(",")
    .map((entry) => entry.trim())
    .filter(Boolean);
  if (candidates.length === 0) return true;

  for (const candidate of candidates) {
    if (candidate === ip) return true;
    if (candidate.includes("/") && isIPv4InCidr(ip, candidate)) return true;
  }

  return false;
}

function getAdminRateLimitPerMinute(env: Env): number {
  const raw = (env.ES_ADMIN_RATE_LIMIT_PER_MIN || "").trim();
  const parsed = parseInt(raw, 10);
  if (!Number.isFinite(parsed)) return ADMIN_RATE_DEFAULT_PER_MIN;
  return Math.max(10, Math.min(600, parsed));
}

async function isAdminRateLimited(ip: string, env: Env): Promise<boolean> {
  const limit = getAdminRateLimitPerMinute(env);
  const key = `${ADMIN_RATE_PREFIX}${ip}`;
  try {
    const current = await env.SESSION_KV.get(key);
    const count = current ? parseInt(current, 10) + 1 : 1;
    await env.SESSION_KV.put(key, String(count), { expirationTtl: ADMIN_RATE_WINDOW_SECONDS });
    return count > limit;
  } catch {
    // Fail-open for availability; token + source allow-list still protect admin endpoints.
    return false;
  }
}

function isAdminAuthorized(request: Request, env: Env): boolean {
  const configured = (env.ES_ADMIN_TOKEN || "").trim();
  if (!configured) return false;

  const xToken = (request.headers.get("X-ES-Admin-Token") || "").trim();
  if (xToken && xToken === configured) return true;

  const auth = (request.headers.get("Authorization") || "").trim();
  if (auth.toLowerCase().startsWith("bearer ")) {
    const bearer = auth.slice(7).trim();
    if (bearer && bearer === configured) return true;
  }
  return false;
}

type AdminIPRequestBody = {
  ip?: string;
  ttlHours?: number;
  reason?: string;
  model?: string;
  fallbacks?: string[] | string;
};

async function parseAdminBody(request: Request): Promise<AdminIPRequestBody | null> {
  try {
    const body = await request.json<AdminIPRequestBody>();
    return body || null;
  } catch {
    return null;
  }
}

async function handleAdminIPRoute(
  request: Request,
  meta: RequestMeta,
  env: Env
): Promise<Response> {
  if (!isAdminAuthorized(request, env)) {
    return createErrorResponse("UNAUTHORIZED", "Invalid admin token", 401);
  }
  if (!isAdminSourceAllowed(meta.ip, env)) {
    return createErrorResponse("FORBIDDEN", "Admin source IP is not allowed", 403);
  }
  if (await isAdminRateLimited(meta.ip, env)) {
    return createErrorResponse("RATE_LIMITED", "Too many admin requests", 429);
  }

  const url = new URL(request.url);
  const path = url.pathname;

  if (request.method === "GET" && path === "/es-admin/ip/status") {
    const ip = (url.searchParams.get("ip") || "").trim();
    if (!ip || !isValidIP(ip)) {
      return createErrorResponse("INVALID_IP", "Valid IP is required", 400);
    }

    const [banned, allowed] = await Promise.all([
      env.SESSION_KV.get(`${TEMP_BAN_PREFIX}${ip}`),
      env.SESSION_KV.get(`${ADMIN_ALLOW_PREFIX}${ip}`),
    ]);

    return createJsonResponse({
      success: true,
      ip,
      banned: banned === "1",
      allowed: allowed !== null,
      allow_meta: allowed ? JSON.parse(allowed) : null,
    });
  }

  if (request.method === "POST" && path === "/es-admin/ip/allow") {
    const body = await parseAdminBody(request);
    const ip = (body?.ip || "").trim();
    if (!ip || !isValidIP(ip)) {
      return createErrorResponse("INVALID_IP", "Valid IP is required", 400);
    }

    const requestedHours = Number(body?.ttlHours);
    const ttlHours = Number.isFinite(requestedHours)
      ? Math.max(1, Math.min(24 * 30, Math.floor(requestedHours)))
      : 24;
    const ttlSeconds = ttlHours * 60 * 60;
    const reason = sanitizeInput(body?.reason || "manual admin allow", 256);

    const allowMeta = JSON.stringify({
      ip,
      reason,
      updated_at: new Date().toISOString(),
      updated_by_ip: meta.ip,
      ttl_hours: ttlHours,
    });

    await env.SESSION_KV.put(`${ADMIN_ALLOW_PREFIX}${ip}`, allowMeta, {
      expirationTtl: ttlSeconds || ADMIN_ALLOW_DEFAULT_TTL_SECONDS,
    });
    await env.SESSION_KV.delete(`${TEMP_BAN_PREFIX}${ip}`);

    return createJsonResponse({
      success: true,
      action: "allow",
      ip,
      ttl_hours: ttlHours,
      note: "IP allow-listed and unbanned",
    });
  }

  if (request.method === "POST" && path === "/es-admin/ip/unban") {
    const body = await parseAdminBody(request);
    const ip = (body?.ip || "").trim();
    if (!ip || !isValidIP(ip)) {
      return createErrorResponse("INVALID_IP", "Valid IP is required", 400);
    }

    await env.SESSION_KV.delete(`${TEMP_BAN_PREFIX}${ip}`);
    return createJsonResponse({
      success: true,
      action: "unban",
      ip,
    });
  }

  if (request.method === "POST" && path === "/es-admin/ip/revoke-allow") {
    const body = await parseAdminBody(request);
    const ip = (body?.ip || "").trim();
    if (!ip || !isValidIP(ip)) {
      return createErrorResponse("INVALID_IP", "Valid IP is required", 400);
    }

    await env.SESSION_KV.delete(`${ADMIN_ALLOW_PREFIX}${ip}`);
    return createJsonResponse({
      success: true,
      action: "revoke_allow",
      ip,
    });
  }

  if (request.method === "GET" && path === "/es-admin/settings/ai") {
    const [savedModel, savedFallbacks] = await Promise.all([
      env.SESSION_KV.get(AI_MODEL_SETTING_KEY),
      env.SESSION_KV.get(AI_MODEL_FALLBACKS_SETTING_KEY),
    ]);
    return createJsonResponse({
      success: true,
      model: savedModel || env.OPENROUTER_MODEL || null,
      fallbacks: savedFallbacks
        ? savedFallbacks.split(",").map((m) => m.trim()).filter(Boolean)
        : ((env.OPENROUTER_FALLBACK_MODELS || "")
            .split(",")
            .map((m) => m.trim())
            .filter(Boolean)),
      source: {
        model: savedModel ? "kv" : "env",
        fallbacks: savedFallbacks ? "kv" : "env",
      },
    });
  }

  if (request.method === "POST" && path === "/es-admin/settings/ai") {
    const body = await parseAdminBody(request);
    if (!body) {
      return createErrorResponse("INVALID_PAYLOAD", "Expected JSON payload", 400);
    }

    const model = sanitizeInput((body.model || "").trim(), 140);
    if (!model) {
      return createErrorResponse("INVALID_MODEL", "model is required", 400);
    }

    const fallbackList = Array.isArray(body.fallbacks)
      ? body.fallbacks
      : typeof body.fallbacks === "string"
        ? body.fallbacks.split(",")
        : [];

    const normalizedFallbacks = fallbackList
      .map((m) => sanitizeInput(String(m).trim(), 140))
      .filter(Boolean)
      .filter((m) => m !== model);

    await env.SESSION_KV.put(AI_MODEL_SETTING_KEY, model);
    await env.SESSION_KV.put(
      AI_MODEL_FALLBACKS_SETTING_KEY,
      normalizedFallbacks.join(",")
    );

    return createJsonResponse({
      success: true,
      action: "save_ai_settings",
      model,
      fallbacks: normalizedFallbacks,
    });
  }

  return createErrorResponse("NOT_FOUND", "Unknown admin route", 404);
}

type CrawlerAllowDecision = {
  allowed: boolean;
  reason: string | null;
};

async function evaluateCrawlerAllowDecision(
  meta: RequestMeta,
  env: Env
): Promise<CrawlerAllowDecision> {
  if (!/^(GET|HEAD)$/i.test(meta.method)) {
    return { allowed: false, reason: null };
  }
  // Strong path: Cloudflare-verified good bot.
  if (meta.verifiedBot) {
    return {
      allowed: true,
      reason: "Verified crawler allow-listed",
    };
  }

  const knownCrawlerUa = KNOWN_CRAWLER_UA_PATTERN.test(meta.userAgent);
  if (!knownCrawlerUa) {
    return { allowed: false, reason: null };
  }

  const family = detectCrawlerFamily(meta.userAgent);
  const strictRdns =
    String(env.ES_CRAWLER_RDNS_VERIFY || "on").toLowerCase() === "on";
  if (strictRdns && family) {
    const verifiedByDns = await verifyCrawlerByReverseDns(meta.ip, family, env);
    if (verifiedByDns) {
      return {
        allowed: true,
        reason: `Known crawler verified by reverse DNS (${family})`,
      };
    }
  }

  // Optional compatibility path for plans where bot verification isn't exposed in request.cf.
  // Keep disabled by default to avoid bypass via spoofed bot User-Agents.
  const allowUaCompat =
    String(env.ES_ALLOW_UA_CRAWLER_ALLOWLIST || "").toLowerCase() === "on";
  if (!allowUaCompat) {
    return { allowed: false, reason: null };
  }

  return {
    allowed: true,
    reason: "Known crawler UA allow-listed (compat mode)",
  };
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
  const hasExploitSignals =
    factors.includes("sensitive config file probe") ||
    factors.includes("known phpunit rce probe") ||
    factors.includes("webshell") ||
    factors.includes("path traversal signature") ||
    factors.includes("unexpected php endpoint probe") ||
    factors.includes("wordpres") ||
    factors.includes("xml-rpc abuse");
  const hasConcentrationSignals =
    factors.includes("asn+path concentration") ||
    factors.includes("asn+path pressure") ||
    factors.includes("path pressure");
  if (hasBurst && risk.score >= 45) return true;
  if (hasExploitSignals && risk.score >= 40) return true;
  if (hasConcentrationSignals && risk.score >= 45) return true;

  if (mode === "aggressive" && risk.score >= 50) return true;

  return false;
}

function shouldAutoBanMalicious(risk: RiskAssessment): boolean {
  if (risk.score >= 85) return true;
  const factors = risk.factors.join(" ").toLowerCase();
  return (
    factors.includes("sensitive config file probe") ||
    factors.includes("known phpunit rce probe") ||
    factors.includes("path traversal signature") ||
    factors.includes("webshell")
  );
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
function normalizeBlockRedirectUrl(raw: string | undefined): string | null {
  const candidate = (raw ?? "").trim();
  if (candidate === "") return null;

  try {
    const parsed = new URL(candidate);
    if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
      return null;
    }
    return parsed.toString();
  } catch {
    return null;
  }
}

function handleHardBlock(env: Env): Response {
  const redirectUrl = normalizeBlockRedirectUrl(env.ES_BLOCK_REDIRECT_URL);
  if (redirectUrl) {
    return new Response(null, {
      status: 302,
      headers: {
        Location: redirectUrl,
        "Cache-Control": "no-store, no-cache, must-revalidate",
      },
    });
  }

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

function isFallbackSubmitRequest(request: Request): boolean {
  if (request.method !== "POST") return false;
  const url = new URL(request.url);
  if (url.searchParams.get("__es_submit") !== "1") return false;
  const nonceHeader = (request.headers.get("X-ES-Nonce") || "").trim();
  return /^[a-f0-9]{16}$/i.test(nonceHeader);
}

function isStaticAssetPath(path: string): boolean {
  if (path === "/favicon.ico") return true;
  return /\.(?:png|jpe?g|gif|webp|svg|ico|css|js|mjs|woff2?|ttf|eot|map|json|txt|xml)$/i.test(path);
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
    if (meta.path.startsWith("/es-admin/")) {
      return handleAdminIPRoute(request, meta, env);
    }

    // Allow-list crawlers for indexing (including Google/Amazon/Bing and peers).
    const crawlerDecision = await evaluateCrawlerAllowDecision(meta, env);
    if (crawlerDecision.allowed) {
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "session_created",
          meta,
          0,
          null,
          crawlerDecision.reason || "Crawler allow-listed"
        )
      );
      return fetch(request);
    }

    // Admin manual allow-list override.
    if (await isAdminAllowedIP(meta.ip, env)) {
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "session_created",
          meta,
          0,
          null,
          "Admin allow-listed IP override"
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
      return handleHardBlock(env);
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
          return handleHardBlock(env);
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
    ctx.waitUntil(incrementPathRate(meta.path, meta.asn, env.SESSION_KV));
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
        return handleHardBlock(env);
      }
    } catch {
      // KV errors are non-fatal; continue normal flow
    }

    // --- Handle Dynamic Challenge Submission (POST) ---
    if (request.method === "POST" && (isDynamicSubmitPath(meta.path) || isFallbackSubmitRequest(request))) {
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

    // Do not issue interactive challenges for static assets.
    if (isStaticAssetPath(meta.path)) {
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
    const fingerprintHint = await getSessionFingerprintHint(request, env);
    const risk = await evaluateRisk(meta, env, fingerprintHint);
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

        // Persist a short temporary ban for high-confidence malicious probes.
        if (shouldAutoBanMalicious(risk)) {
          try {
            await env.SESSION_KV.put(`${TEMP_BAN_PREFIX}${meta.ip}`, "1", {
              expirationTtl: TEMP_BAN_TTL_SECONDS,
            });
            ctx.waitUntil(
              logSecurityEvent(
                env,
                "hard_block",
                meta,
                risk.score,
                null,
                `Auto-banned by malicious signature (${TEMP_BAN_TTL_SECONDS}s window)`
              )
            );
          } catch {
            // KV failures are non-fatal
          }
        }

        // Trigger async AI defense only when attack indicators are strong.
        if (shouldTriggerAIDefense(risk, securityMode)) {
          ctx.waitUntil(triggerAIDefenseIfReady(env, meta));
        }

        return handleHardBlock(env);
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
