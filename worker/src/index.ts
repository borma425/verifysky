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
  IpAccessRuleRecord,
  CustomFirewallRuleRecord,
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
  getDailyHoneypotPaths,
  parseThresholds,
  isAdTraffic,
} from "./utils";
import {
  evaluateRisk,
  incrementIPRate,
  incrementASNRate,
  incrementSubnetRate,
  incrementPathRate,
  getIPRateCount,
  incrementFloodCounters,
  getFloodStatus,
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

/** Session token validity duration (1 hour) */
const SESSION_TTL_SECONDS = 1 * 60 * 60;

/** KV key prefix for domain config caching */
const DOMAIN_CACHE_PREFIX = "dcfg:";
const DOMAIN_CACHE_TTL_SECONDS = 300; // 5 minutes cache for domain configs

// Domain-scoped KV Key Helpers
function getTempBanKey(domain: string, ip: string) { return `ban:domainIP:${domain}:${ip}`; }
function getAdminAllowKey(domain: string, ip: string) { return `allow:domainIP:${domain}:${ip}`; }
function getTrustedIpKey(domain: string, ip: string) { return `trust:domainIP:${domain}:${ip}`; }
function getDailyVisitKey(domain: string, ip: string) { return `daily_visit:domainIP:${domain}:${ip}`; }
function getDomainKeyVariants(domain: string): string[] {
  const normalized = domain.trim().toLowerCase();
  if (!normalized) return [];

  const variants = new Set<string>([normalized]);
  if (normalized.startsWith("www.") && normalized.length > 4) {
    variants.add(normalized.slice(4));
  } else if (normalized.includes(".")) {
    variants.add(`www.${normalized}`);
  }
  return Array.from(variants);
}

/** KV key prefix for IP rules caching */
const IP_RULES_CACHE_PREFIX = "ipr:";
const IP_RULES_CACHE_TTL = 120;

const TEMP_BAN_TTL_SECONDS = 24 * 60 * 60;
const ADMIN_ALLOW_DEFAULT_TTL_SECONDS = 24 * 60 * 60;
const ADMIN_RATE_PREFIX = "rate:admin:";
const ADMIN_RATE_WINDOW_SECONDS = 60;
const ADMIN_RATE_DEFAULT_PER_MIN = 60;
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
const AI_MODEL_SETTING_KEY = "settings:ai:model";
const AI_MODEL_FALLBACKS_SETTING_KEY = "settings:ai:fallbacks";
const AI_LAST_RUN_KEY = "ai:last_run";
const AI_COOLDOWN_SECONDS = 300;
const IP_HARD_BAN_RATE_THRESHOLD = 120; // requests/minute per IP
const IP_FARM_DESC_PREFIX = "[IP-FARM]";
const IP_FARM_MAX_TARGETS_PER_RULE = 500;
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

// Dynamic log sampling — reduces D1 pressure for normal-pass events
const TRAFFIC_COUNTER_KEY = "traffic:current_minute";
const TRAFFIC_COUNTER_TTL = 60;
const SAMPLE_RATE_LOW = 0.20;   // 20% when traffic < 500/min
const SAMPLE_RATE_MED = 0.05;   // 5%  when traffic 500-2000/min
const SAMPLE_RATE_HIGH = 0.01;  // 1%  when traffic > 2000/min
const TRAFFIC_THRESHOLD_MED = 500;
const TRAFFIC_THRESHOLD_HIGH = 2000;

// Trusted IP decision cache — skip risk engine for recently-safe IPs
const TRUSTED_IP_PREFIX = "trusted:";
const TRUSTED_IP_TTL_SECONDS = 2 * 60; // 2 minutes

// Visit counter — CAPTCHA after N page views in a rolling window
const VISIT_COUNTER_PREFIX = "vc:";
const VISIT_COUNTER_TTL = 180;           // 3-minute rolling window

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
          expirationTtl: DOMAIN_CACHE_TTL_SECONDS,
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

/**
 * Resolves the custom IP access rules (allow/block) for a domain from D1 with KV caching.
 */
async function resolveDomainIpRules(
  domain: string,
  env: Env
): Promise<IpAccessRuleRecord[]> {
  const normalizedDomain = domain.toLowerCase();
  const cacheKey = `${IP_RULES_CACHE_PREFIX}${normalizedDomain}`;

  try {
    const cached = await env.SESSION_KV.get(cacheKey);
    if (cached) {
      return JSON.parse(cached) as IpAccessRuleRecord[];
    }
  } catch {}

  try {
    const { results } = await env.DB.prepare(
      "SELECT * FROM ip_access_rules WHERE domain_name = ?"
    )
      .bind(normalizedDomain)
      .all<IpAccessRuleRecord>();

    let rules = results || [];

    if (rules.length === 0 && normalizedDomain.startsWith("www.")) {
      const apexDomain = normalizedDomain.slice(4);
      const apexRes = await env.DB.prepare(
        "SELECT * FROM ip_access_rules WHERE domain_name = ?"
      )
        .bind(apexDomain)
        .all<IpAccessRuleRecord>();
      if (apexRes.results) {
        rules = apexRes.results;
      }
    }

    try {
      await env.SESSION_KV.put(cacheKey, JSON.stringify(rules), {
        expirationTtl: IP_RULES_CACHE_TTL,
      });
    } catch {}

    return rules;
  } catch {
    return [];
  }
}

interface SensitivePathRecord {
  id: number;
  domain_name: string;
  path_pattern: string;
  match_type: string;
  action: string;
}

async function resolveSensitivePaths(domain: string, env: Env): Promise<SensitivePathRecord[]> {
  const normalizedDomain = domain.toLowerCase();
  const cacheKey = `cfr:sensitive_paths:${normalizedDomain}`;
  try {
    const cached = await env.SESSION_KV.get(cacheKey);
    if (cached) return JSON.parse(cached) as SensitivePathRecord[];
  } catch {}

  try {
    const { results } = await env.DB.prepare(
      "SELECT * FROM sensitive_paths WHERE (domain_name = ? OR domain_name = 'global') ORDER BY id DESC"
    ).bind(normalizedDomain).all<SensitivePathRecord>();

    const rules = results || [];
    try {
      await env.SESSION_KV.put(cacheKey, JSON.stringify(rules), {
        expirationTtl: IP_RULES_CACHE_TTL,
      });
    } catch {}
    
    return rules;
  } catch {
    return [];
  }
}

async function resolveCustomFirewallRules(
  domain: string,
  env: Env
): Promise<CustomFirewallRuleRecord[]> {
  const normalizedDomain = domain.toLowerCase();
  const cacheKey = `cfr:${normalizedDomain}`;

  try {
    const cached = await env.SESSION_KV.get(cacheKey);
    if (cached) {
      return JSON.parse(cached) as CustomFirewallRuleRecord[];
    }
  } catch {}

  try {
    const { results } = await env.DB.prepare(
      "SELECT * FROM custom_firewall_rules WHERE (domain_name = ? OR domain_name = 'global') AND paused = 0 ORDER BY id DESC"
    )
      .bind(normalizedDomain)
      .all<CustomFirewallRuleRecord>();

    let rules = results || [];

    if (rules.length === 0 && normalizedDomain.startsWith("www.")) {
      const apexDomain = normalizedDomain.slice(4);
      const apexRes = await env.DB.prepare(
        "SELECT * FROM custom_firewall_rules WHERE (domain_name = ? OR domain_name = 'global') AND paused = 0 ORDER BY id DESC"
      )
        .bind(apexDomain)
        .all<CustomFirewallRuleRecord>();
      if (apexRes.results) {
        rules = apexRes.results;
      }
    }

    // PRIORITY SORT: Allow/bypass rules MUST be evaluated before block rules.
    // This ensures admin allow-list always overrides IP Farm permanent bans.
    rules.sort((a, b) => {
      const aIsAllow = a.action === "allow" || a.action === "bypass" ? 0 : 1;
      const bIsAllow = b.action === "allow" || b.action === "bypass" ? 0 : 1;
      return aIsAllow - bIsAllow;
    });

    try {
      await env.SESSION_KV.put(cacheKey, JSON.stringify(rules), {
        expirationTtl: IP_RULES_CACHE_TTL,
      });
    } catch {}

    return rules;
  } catch {
    return [];
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
    await incrementHistoricalAttackCounters(env, eventType, meta.ip, meta);
  } catch {
    // Logging failure must never crash the Worker
  }
}

function utcDayKey(now: Date = new Date()): string {
  return now.toISOString().slice(0, 10);
}

function utcMonthKey(now: Date = new Date()): string {
  return now.toISOString().slice(0, 7);
}

/** Monthly hard_block threshold before auto-escalation to IP Farm */
const IP_FARM_AUTO_ESCALATION_THRESHOLD = 3;

async function incrementHistoricalAttackCounters(
  env: Env,
  eventType: string,
  ip: string,
  meta: RequestMeta | null
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

  let monthCount = 1;
  try {
    const currentMonth = await env.SESSION_KV.get(monthKey);
    monthCount = currentMonth ? parseInt(currentMonth, 10) + 1 : 1;
    await env.SESSION_KV.put(monthKey, String(monthCount), {
      expirationTtl: ATTACK_COUNTER_TTL_SECONDS,
    });
  } catch {
    // Non-fatal
  }

  // --- Auto-Escalation to IP Farm ---
  // If this IP has been hard_blocked 3+ times this month, permanently ban it.
  // Only triggers on hard_block events to avoid false positives from challenges.
  if (
    eventType === "hard_block" &&
    monthCount >= IP_FARM_AUTO_ESCALATION_THRESHOLD &&
    meta
  ) {
    try {
      await markForIpFarm(
        ip,
        env,
        meta,
        `Auto-escalation: ${monthCount} hard_blocks in ${utcMonthKey()} (threshold: ${IP_FARM_AUTO_ESCALATION_THRESHOLD})`
      );
    } catch {
      // Non-fatal — IP Farm write failure should never crash the Worker
    }
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

// ---------------------------------------------------------------------------
// Dynamic Log Sampling — reduces D1 write pressure for normal-pass events
// ---------------------------------------------------------------------------

/**
 * Increments the global per-minute traffic counter and returns the current count.
 * Used to determine optimal sampling rate.
 */
async function getTrafficLevel(env: Env): Promise<number> {
  try {
    const current = await env.SESSION_KV.get(TRAFFIC_COUNTER_KEY);
    const count = current ? parseInt(current, 10) + 1 : 1;
    await env.SESSION_KV.put(TRAFFIC_COUNTER_KEY, String(count), {
      expirationTtl: TRAFFIC_COUNTER_TTL,
    });
    return count;
  } catch {
    return 0; // Fail-open: unknown traffic → sample at default rate
  }
}

/**
 * Dynamically samples normal-pass log events.
 * - Block/Challenge: always 100% (not handled here)
 * - Normal pass: 20% at low traffic, 5% at medium, 1% at high
 *
 * When sampled, the log details include "[sampled]" marker for clarity.
 */
async function samplePassLog(
  env: Env,
  meta: RequestMeta,
  riskScore: number,
  details: string
): Promise<void> {
  const trafficLevel = await getTrafficLevel(env);

  let sampleRate: number;
  if (trafficLevel > TRAFFIC_THRESHOLD_HIGH) {
    sampleRate = SAMPLE_RATE_HIGH;
  } else if (trafficLevel > TRAFFIC_THRESHOLD_MED) {
    sampleRate = SAMPLE_RATE_MED;
  } else {
    sampleRate = SAMPLE_RATE_LOW;
  }

  if (Math.random() >= sampleRate) return; // Skip this event

  await logSecurityEvent(
    env,
    "session_created",
    meta,
    riskScore,
    null,
    `[sampled ${Math.round(sampleRate * 100)}%] ${details}`
  );
}

// ---------------------------------------------------------------------------
// Visit Counter — per-IP+UA page view tracking for CAPTCHA triggering
// ---------------------------------------------------------------------------

/**
 * SHA-256 shortened hash (first 8 hex chars) for UA fingerprinting.
 * Used to build composite visit counter keys (IP + UA).
 */
async function visitCounterUAHash(userAgent: string): Promise<string> {
  const data = new TextEncoder().encode(userAgent || "unknown");
  const hashBuffer = await crypto.subtle.digest("SHA-256", data);
  const hashArray = new Uint8Array(hashBuffer);
  let hex = "";
  for (let i = 0; i < 4; i++) {
    hex += hashArray[i].toString(16).padStart(2, "0");
  }
  return hex;
}

/**
 * Reads the visit counter for an IP+UA composite key, increments it,
 * and returns the new count. The counter auto-expires after VISIT_COUNTER_TTL.
 *
 * Returns the count AFTER increment. If count >= VISIT_CAPTCHA_THRESHOLD,
 * the caller should serve a CAPTCHA.
 */
async function checkAndIncrementVisitCounter(
  meta: RequestMeta,
  env: Env
): Promise<number> {
  const uaHash = await visitCounterUAHash(meta.userAgent);
  const key = `${VISIT_COUNTER_PREFIX}${meta.ip}:${uaHash}`;

  try {
    const current = await env.SESSION_KV.get(key);
    const count = current ? parseInt(current, 10) + 1 : 1;
    await env.SESSION_KV.put(key, String(count), {
      expirationTtl: VISIT_COUNTER_TTL,
    });
    return count;
  } catch {
    // KV failure — fail-open to avoid blocking legitimate users
    return 0;
  }
}

/**
 * Increments and tracks visits over a 24-hour window per IP per domain.
 * This is designed specifically to catch slow-drip click fraud attacks.
 */
async function checkAndIncrementDailyVisitCounter(
  meta: RequestMeta,
  domain: string,
  env: Env
): Promise<number> {
  const uaHash = await visitCounterUAHash(meta.userAgent);
  const key = getDailyVisitKey(domain, meta.ip) + ":" + uaHash;
  try {
    const current = await env.SESSION_KV.get(key);
    const count = current ? parseInt(current, 10) + 1 : 1;
    await env.SESSION_KV.put(key, String(count), {
      expirationTtl: 86400, // 24 hours
    });
    return count;
  } catch {
    return 0;
  }
}

/**
 * Tracks total unverified visits originating from a specific ASN over a 1-hour window.
 * This is designed specifically to challenge distributed proxy attacks from the same network provider.
 */
async function checkAndIncrementASNVisitCounter(
  asn: string,
  domain: string,
  env: Env
): Promise<number> {
  const key = `asn_visit:domainASN:${domain}:${asn}`;
  try {
    const current = await env.SESSION_KV.get(key);
    const count = current ? parseInt(current, 10) + 1 : 1;
    await env.SESSION_KV.put(key, String(count), {
      expirationTtl: 3600, // 1 hour sliding window
    });
    return count;
  } catch {
    return 0;
  }
}

/**
 * Checks whether an IP is temporarily banned in KV.
 * Returns true when ban marker is present.
 */
async function isTemporarilyBanned(ip: string, domain: string, env: Env): Promise<boolean> {
  try {
    const domains = getDomainKeyVariants(domain);
    const values = await Promise.all(
      domains.map((name) => env.SESSION_KV.get(getTempBanKey(name, ip)))
    );
    return values.some((ban) => ban === "1");
  } catch {
    return false;
  }
}

async function isAdminAllowedIP(ip: string, domain: string, env: Env): Promise<boolean> {
  try {
    const domains = getDomainKeyVariants(domain);
    const values = await Promise.all(
      domains.map((name) => env.SESSION_KV.get(getAdminAllowKey(name, ip)))
    );
    return values.some((allow) => allow !== null);
  } catch {
    return false;
  }
}

/**
 * Checks whether an IP is explicitly allow-listed in custom_firewall_rules
 * (domain-specific or global) with action allow/bypass and an ip.src expression.
 * This is used as an override before temp-ban checks to prevent stale KV bans
 * from blocking manually allow-listed admin IPs.
 */
async function isCustomFirewallIpAllowed(
  ip: string,
  domain: string,
  env: Env
): Promise<boolean> {
  try {
    const rules = await resolveCustomFirewallRules(domain, env);
    if (!rules.length) return false;

    const nowSecs = Math.floor(Date.now() / 1000);
    const normalizedIp = ip.toLowerCase();

    for (const rule of rules) {
      const isAllow = rule.action === "allow" || rule.action === "bypass";
      if (!isAllow) continue;
      if (rule.expires_at !== null && rule.expires_at < nowSecs) continue;

      try {
        const expr = JSON.parse(rule.expression_json);
        if (expr?.field !== "ip.src") continue;

        const operator = String(expr.operator || "").toLowerCase();
        const value = String(expr.value || "").toLowerCase().trim();
        if (!value) continue;

        if (operator === "eq") {
          if (value.includes("/")) {
            if (isIpInCidr(ip, value)) return true;
          } else if (value === normalizedIp) {
            return true;
          }
          continue;
        }

        if (operator === "in") {
          const list = value.split(",").map((v: string) => v.trim()).filter(Boolean);
          if (
            list.some((target: string) =>
              target.includes("/") ? isIpInCidr(ip, target) : target === normalizedIp
            )
          ) {
            return true;
          }
        }
      } catch {
        // Ignore malformed rule expressions
      }
    }
  } catch {
    // Fail closed: continue normal security flow
  }

  return false;
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

function ipv6ToBigInt(ip: string): bigint | null {
  if (!ip.includes(":")) return null;
  const parts = ip.split("::");
  if (parts.length > 2) return null;
  const left = parts[0] ? parts[0].split(":") : [];
  const right = parts.length === 2 && parts[1] ? parts[1].split(":") : [];
  if (parts.length === 1 && left.length !== 8) return null;
  const missing = 8 - (left.length + right.length);
  if (missing < 0) return null;
  const groups = [...left, ...Array<string>(missing).fill("0"), ...right];
  let val = 0n;
  for (let i = 0; i < 8; i++) {
    const groupVal = groups[i] === "" ? 0 : parseInt(groups[i], 16);
    if (Number.isNaN(groupVal) || groupVal > 0xffff || groupVal < 0) return null;
    val = (val << 16n) | BigInt(groupVal);
  }
  return val;
}

function isIPv6InCidr(ip: string, cidr: string): boolean {
  const [base, maskText] = cidr.split("/");
  if (!base || !maskText) return false;
  const maskBits = parseInt(maskText, 10);
  if (!Number.isFinite(maskBits) || maskBits < 0 || maskBits > 128) return false;
  const ipInt = ipv6ToBigInt(ip);
  const baseInt = ipv6ToBigInt(base);
  if (ipInt === null || baseInt === null) return false;
  if (maskBits === 0) return true;
  const shiftBits = 128n - BigInt(maskBits);
  return (ipInt >> shiftBits) === (baseInt >> shiftBits);
}

function isIpInCidr(ip: string, cidr: string): boolean {
  if (cidr.includes(":")) {
    if (!ip.includes(":")) return false;
    return isIPv6InCidr(ip, cidr);
  } else if (cidr.includes(".")) {
    if (!ip.includes(".")) return false;
    return isIPv4InCidr(ip, cidr);
  }
  return false;
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
    if (candidate.includes("/") && isIpInCidr(ip, candidate)) return true;
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
  domain: string,
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

    const domains = getDomainKeyVariants(domain);
    const [banRows, allowRows, customFirewallAllowed] = await Promise.all([
      Promise.all(
        domains.map(async (name) => ({
          domain: name,
          value: await env.SESSION_KV.get(getTempBanKey(name, ip)),
        }))
      ),
      Promise.all(
        domains.map(async (name) => ({
          domain: name,
          value: await env.SESSION_KV.get(getAdminAllowKey(name, ip)),
        }))
      ),
      isCustomFirewallIpAllowed(ip, domain, env),
    ]);

    const banMatch = banRows.find((row) => row.value === "1") || null;
    const allowMatch = allowRows.find((row) => row.value !== null) || null;
    let allowMeta: unknown = null;
    if (allowMatch?.value) {
      try {
        allowMeta = JSON.parse(allowMatch.value);
      } catch {
        allowMeta = { raw: allowMatch.value };
      }
    }
    const effectiveAllowed = Boolean(allowMatch) || customFirewallAllowed;

    return createJsonResponse({
      success: true,
      ip,
      domain_checked: domain,
      domain_variants_checked: domains,
      banned: Boolean(banMatch),
      banned_source_domain: banMatch?.domain || null,
      allowed: Boolean(allowMatch),
      allowed_source_domain: allowMatch?.domain || null,
      allow_meta: allowMeta,
      custom_firewall_allowed: customFirewallAllowed,
      effective_allowed: effectiveAllowed,
      effective_blocked: Boolean(banMatch) && !effectiveAllowed,
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

    const domains = getDomainKeyVariants(domain);
    await Promise.all(
      domains.map(async (name) => {
        await env.SESSION_KV.put(getAdminAllowKey(name, ip), allowMeta, {
          expirationTtl: ttlSeconds || ADMIN_ALLOW_DEFAULT_TTL_SECONDS,
        });
        await env.SESSION_KV.delete(getTempBanKey(name, ip));
      })
    );

    return createJsonResponse({
      success: true,
      action: "allow",
      ip,
      ttl_hours: ttlHours,
      domains_updated: domains,
      note: "IP allow-listed and unbanned",
    });
  }

  if (request.method === "POST" && path === "/es-admin/ip/ban") {
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
    const reason = sanitizeInput(body?.reason || "manual admin block", 256);

    const domains = getDomainKeyVariants(domain);
    await Promise.all(
      domains.map(async (name) => {
        await env.SESSION_KV.put(getTempBanKey(name, ip), "1", {
          expirationTtl: ttlSeconds || TEMP_BAN_TTL_SECONDS,
        });
        await env.SESSION_KV.delete(getAdminAllowKey(name, ip));
      })
    );

    return createJsonResponse({
      success: true,
      action: "ban",
      ip,
      ttl_hours: ttlHours,
      domains_updated: domains,
      note: `IP blocked (${reason})`,
    });
  }

  if (request.method === "POST" && path === "/es-admin/ip/unban") {
    const body = await parseAdminBody(request);
    const ip = (body?.ip || "").trim();
    if (!ip || !isValidIP(ip)) {
      return createErrorResponse("INVALID_IP", "Valid IP is required", 400);
    }

    const domains = getDomainKeyVariants(domain);
    await Promise.all(domains.map((name) => env.SESSION_KV.delete(getTempBanKey(name, ip))));
    return createJsonResponse({
      success: true,
      action: "unban",
      ip,
      domains_updated: domains,
    });
  }

  if (request.method === "POST" && path === "/es-admin/ip/revoke-allow") {
    const body = await parseAdminBody(request);
    const ip = (body?.ip || "").trim();
    if (!ip || !isValidIP(ip)) {
      return createErrorResponse("INVALID_IP", "Valid IP is required", 400);
    }

    const domains = getDomainKeyVariants(domain);
    await Promise.all(domains.map((name) => env.SESSION_KV.delete(getAdminAllowKey(name, ip))));
    return createJsonResponse({
      success: true,
      action: "revoke_allow",
      ip,
      domains_updated: domains,
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

// ---------------------------------------------------------------------------
// IP Farm — Permanent Ban Engine
// Sends confirmed malicious IPs to the "graveyard" via D1 Global Firewall rules.
// These rules have NO expiry (expires_at = NULL) and apply to ALL domains.
// Uses smart merging: fills existing non-full [IP-FARM] rules before creating new ones.
// ---------------------------------------------------------------------------

/**
 * Checks whether the given IP matches any 'allow' or 'bypass' rule in the
 * custom_firewall_rules table for 'global' or any domain. If so, the IP
 * must NOT be added to the farm.
 */
async function isIpAllowListed(ip: string, env: Env): Promise<boolean> {
  try {
    const rulesRes = await env.DB.prepare(
      `SELECT expression_json FROM custom_firewall_rules
       WHERE (action = 'allow' OR action = 'bypass')
         AND paused = 0
         AND (expires_at IS NULL OR expires_at > ?)
       LIMIT 200`
    )
      .bind(Math.floor(Date.now() / 1000))
      .all<{ expression_json: string }>();

    if (!rulesRes.results) return false;

    const lowerIp = ip.toLowerCase();
    for (const rule of rulesRes.results) {
      try {
        const expr = JSON.parse(rule.expression_json);
        if (expr.field === "ip.src") {
          if (expr.operator === "eq" && expr.value.toLowerCase() === lowerIp) return true;
          if (expr.operator === "in") {
            const list = String(expr.value).split(",").map((v: string) => v.trim().toLowerCase());
            if (list.some((target: string) => target.includes("/") ? isIpInCidr(ip, target) : target === lowerIp)) return true;
          }
        }
      } catch {
        // Skip invalid JSON
      }
    }
    return false;
  } catch {
    return false; // Fail-open: don't block the farm pipeline
  }
}

/**
 * Permanently bans an IP by adding it to a Global Firewall [IP-FARM] rule.
 * - Checks allow-list bypass first
 * - Deduplicates across all existing farm rules
 * - Fills non-full rules before creating new ones
 * - Rules have NO expiry (permanent ban)
 * - domain_name = 'global' (applies to all domains)
 *
 * Designed to be called via ctx.waitUntil() — fully async, non-blocking.
 */
async function markForIpFarm(
  ip: string,
  env: Env,
  meta: RequestMeta,
  reason: string
): Promise<void> {
  try {
    // Validate IP
    if (!ip || ip === "127.0.0.1" || ip === "::1") return;
    if (/^10\./.test(ip) || /^192\.168\./.test(ip) || /^172\.(1[6-9]|2[0-9]|3[0-1])\./.test(ip)) return;

    // Check allow-list bypass
    if (await isIpAllowListed(ip, env)) return;

    const lowerIp = ip.toLowerCase().trim();
    const nowSecs = Math.floor(Date.now() / 1000);

    // Fetch all existing [IP-FARM] rules
    const existingRes = await env.DB.prepare(
      `SELECT id, expression_json
       FROM custom_firewall_rules
       WHERE description LIKE ?
         AND action = 'block'
         AND paused = 0
       ORDER BY id ASC`
    )
      .bind(`${IP_FARM_DESC_PREFIX}%`)
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

            // Check if IP already exists in this rule
            if (targets.includes(lowerIp)) return; // Already in farm

            parsedRules.push({
              id: rule.id,
              expression_json: rule.expression_json,
              targets,
            });
          }
        } catch {
          // Skip invalid JSON
        }
      }
    }

    // Find a non-full rule to merge into
    let merged = false;
    for (const pRule of parsedRules) {
      if (pRule.targets.length < IP_FARM_MAX_TARGETS_PER_RULE) {
        const mergedTargets = [...pRule.targets, lowerIp];
        const newExpression = JSON.stringify({
          field: "ip.src",
          operator: "in",
          value: mergedTargets.join(", "),
        });

        const farmNumber = parsedRules.indexOf(pRule) + 1;
        const newDescription = `${IP_FARM_DESC_PREFIX} IP Farm ${farmNumber} (${mergedTargets.length} IPs)`;

        await env.DB.prepare(
          `UPDATE custom_firewall_rules
           SET expression_json = ?, description = ?, updated_at = CURRENT_TIMESTAMP
           WHERE id = ? AND expression_json = ?`
        )
          .bind(newExpression, newDescription, pRule.id, pRule.expression_json)
          .run();

        merged = true;
        break;
      }
    }

    // If all rules are full (or none exist), create a new rule
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
        .bind(
          `${IP_FARM_DESC_PREFIX} IP Farm ${farmNumber} (1 IPs)`,
          newExpression
        )
        .run();
    }

    // Purge KV cache so the worker picks up the change instantly
    try {
      await env.SESSION_KV.delete("cfr:global");
    } catch {}

    // Log the farm event
    await logSecurityEvent(
      env,
      "hard_block" as any,
      meta,
      100,
      null,
      `[IP-FARM] Permanent ban: ${reason}`
    );
  } catch {
    // IP Farm pipeline must never crash the Worker
  }
}

function getIpSubnet(ip: string): string {
  if (!ip) return "unknown";
  if (ip.includes(":")) {
    const parts = ip.split(":");
    return parts.slice(0, 4).join(":") + "::/64";
  }
  const parts = ip.split(".");
  if (parts.length === 4) {
    return parts.slice(0, 3).join(".") + ".0/24";
  }
  return ip;
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

  const thresholds = parseThresholds(domainConfig);
  const pressureTtl = thresholds.auto_aggr_pressure_seconds || AUTO_AGGR_PRESSURE_TTL_SECONDS;
  const activeTtl = thresholds.auto_aggr_active_seconds || AUTO_AGGR_ACTIVE_TTL_SECONDS;
  const triggerCount = thresholds.auto_aggr_trigger_subnets || AUTO_AGGR_TRIGGER_COUNT;

  const activeKey = `${AUTO_AGGR_ACTIVE_PREFIX}${domain}`;
  const pressureKey = `${AUTO_AGGR_PRESSURE_PREFIX}${domain}`;

  try {
    const active = await env.SESSION_KV.get(activeKey);
    if (active === "1") return "aggressive";

    if (!hasAttackPressureSignal(risk)) return configured;

    let subnets: string[] = [];
    const stored = await env.SESSION_KV.get(pressureKey);
    if (stored) {
      try {
        subnets = JSON.parse(stored);
        if (!Array.isArray(subnets)) subnets = [];
      } catch {
        subnets = [];
      }
    }

    const subnet = getIpSubnet(meta.ip);
    if (!subnets.includes(subnet)) {
      subnets.push(subnet);
      if (subnets.length > 20) subnets.shift();
      await env.SESSION_KV.put(pressureKey, JSON.stringify(subnets), {
        expirationTtl: pressureTtl,
      });
    }

    const next = subnets.length;
    if (next >= triggerCount) {
      await env.SESSION_KV.put(activeKey, "1", {
        expirationTtl: activeTtl,
      });
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "mode_escalated",
          meta,
          risk.score,
          null,
          `Auto-escalated domain to aggressive for ${activeTtl}s (distributed pressure, ${next} unique subnets)`
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
    const thresholds = parseThresholds(domainConfig);
    return handleChallengeSubmission(request, meta, domainConfig, thresholds, env, ctx);
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
      return handleAdminIPRoute(request, meta, domain, env);
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
    if (await isAdminAllowedIP(meta.ip, domain, env)) {
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
      return fetchWithInjectors(request, meta, env, false);
    }

    // Custom firewall allow-list override for explicit IP rules.
    // This is evaluated before temp-ban checks so intentional allow-rules
    // can safely override stale temporary ban markers.
    if (await isCustomFirewallIpAllowed(meta.ip, domain, env)) {
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "session_created",
          meta,
          0,
          null,
          "Custom firewall allow-listed IP override"
        )
      );
      return fetchWithInjectors(request, meta, env, true);
    }

    // --- Trusted IP Decision Cache ---
    // If this IP was recently assessed as safe (score ≤ 15), skip the
    // expensive risk scoring engine (14 factors + D1 queries).
    // IMPORTANT: Flood protection and IP rate hard-ban STILL apply even
    // for trusted IPs to prevent abuse of the trust window for click fraud.
    let isTrustedIP = false;
    try {
      const cachedDecision = await env.SESSION_KV.get(getTrustedIpKey(domain, meta.ip));
      if (cachedDecision === "1") {
        isTrustedIP = true;
        // Do NOT return early — continue to flood protection below
      }
    } catch {
      // Cache miss is fine — continue normal flow
    }

    // Fast hard-stop for known abusive IPs (temporary ban window).
    if (await isTemporarilyBanned(meta.ip, domain, env)) {
      ctx.waitUntil(
        logSecurityEvent(
          env,
          "hard_block",
          meta,
          100,
          null,
          `Temporarily banned IP`
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
        const thresholds = parseThresholds(domainConfig || null);
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

    // --- Handle Dynamic Challenge Submission (POST) — HIGHEST PRIORITY ---
    // Must run BEFORE custom firewall rules and sensitive path checks.
    // Otherwise, broad rules (e.g. "user_agent ne mobi" → managed_challenge)
    // intercept the solve POST and serve a NEW challenge, creating an infinite
    // loop where the user can never complete verification.
    // Security is NOT weakened: the challenge handler has its own full
    // validation chain (nonce, signature, IP match, telemetry, X-position,
    // Turnstile). Temp-ban check above already blocks banned IPs.
    if (request.method === "POST" && (isDynamicSubmitPath(meta.path) || isFallbackSubmitRequest(request))) {
      return handleSubmission(request, meta, domainConfig, env, ctx);
    }

    let thresholds = parseThresholds(domainConfig);

    // --- Factor: Ad Traffic Strictness ---
    if (thresholds.ad_traffic_strict_mode && isAdTraffic(meta.url)) {
      thresholds = {
        ...thresholds,
        visit_captcha_threshold: Math.max(1, Math.floor(thresholds.visit_captcha_threshold / 2)),
        daily_visit_limit: Math.max(1, Math.floor(thresholds.daily_visit_limit / 2)),
        asn_hourly_visit_limit: Math.max(1, Math.floor(thresholds.asn_hourly_visit_limit / 2)),
        flood_burst_challenge: Math.max(1, Math.floor(thresholds.flood_burst_challenge / 2)),
        flood_burst_block: Math.max(1, Math.floor(thresholds.flood_burst_block / 2)),
        flood_sustained_challenge: Math.max(1, Math.floor(thresholds.flood_sustained_challenge / 2)),
        flood_sustained_block: Math.max(1, Math.floor(thresholds.flood_sustained_block / 2)),
      };
    }

    // --- KV Session Validation (Early Check) ---
    // We validate the session early so that WAF and Custom Firewall rules
    // with action "challenge" can be bypassed by users who just solved one.
    const session = await validateSession(request, meta, env);

    // --- Sensitive Paths WAF Module ---
    // High-priority evaluation for critical internal URIs (.env, wp-login, etc.)
    const sensitivePaths = await resolveSensitivePaths(domain, env);
    const lowercasePath = meta.path.toLowerCase();
    
    for (const rule of sensitivePaths) {
      let isMatch = false;
      const pattern = rule.path_pattern.toLowerCase();
      
      if (rule.match_type === "exact") {
        isMatch = (lowercasePath === pattern);
      } else if (rule.match_type === "contains") {
        isMatch = lowercasePath.includes(pattern);
      } else if (rule.match_type === "ends_with") {
        isMatch = lowercasePath.endsWith(pattern);
      }
      
      if (isMatch) {
         const isBlock = rule.action === "block";
         const isChallenge = rule.action === "challenge" || rule.action === "managed_challenge" || rule.action === "js_challenge";
         
         const logAction = isBlock ? "hard_block" : (isChallenge ? (session ? "session_created" : "challenge_issued") : "session_created");
         
         ctx.waitUntil(
            logSecurityEvent(
               env,
               logAction as any,
               meta,
               isBlock ? 100 : 0,
               null,
               `Sensitive Path Protection: ${pattern} (${rule.match_type})${isChallenge && session ? ' (Bypassed via session)' : ''}`
            )
         );

         if (isBlock) {
             try {
                await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
                  expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
                });
             } catch {}
              // IP Farm: Hard Block sensitive path → permanent ban
              ctx.waitUntil(markForIpFarm(meta.ip, env, meta, `Sensitive path hard block: ${pattern} (${rule.match_type})`));
             return handleHardBlock(env);
         } else if (isChallenge) {
             if (session) {
                 continue; // They already solved a challenge, proceed to next WAF rule
             }
              // IP Farm: Challenge sensitive path — only if IP has prior failures
              try {
                const priorFails = await env.SESSION_KV.get(`failrate:${meta.ip}`);
                if (priorFails && parseInt(priorFails, 10) > 0) {
                  ctx.waitUntil(markForIpFarm(meta.ip, env, meta, `Sensitive path challenge with ${priorFails} prior failures: ${pattern}`));
                }
              } catch {}
             return serveChallengePagePlaceholder(meta, domainConfig, env);
         }
      }
    }

    // --- Global Custom Firewall Rules ---
    // Evaluated directly at the edge, BEFORE any session or risk checks.
    // Replaces the legacy ip_access_rules because it natively supports complex combinations.
    const fwRules = await resolveCustomFirewallRules(domain, env);
    const nowSecs = Math.floor(Date.now() / 1000);

    for (const rule of fwRules) {
      // Lazy Expiration: Skip this rule instantly if it has expired
      if (rule.expires_at !== null && rule.expires_at < nowSecs) {
        continue;
      }

      let conditionMatch = false;
      try {
        const expr = JSON.parse(rule.expression_json);
        const field = expr.field;
        const operator = expr.operator;
        const targetValue = String(expr.value).toLowerCase();
        
        let actualValue = "";
        if (field === "ip.src") {
            actualValue = meta.ip.toLowerCase();
        } else if (field === "ip.src.country") {
            actualValue = (meta.country || "").toLowerCase();
        } else if (field === "ip.src.asnum") {
            actualValue = (meta.asn || "").toLowerCase();
        } else if (field === "http.request.uri.path") {
            actualValue = meta.path.toLowerCase();
        } else if (field === "http.request.method") {
            actualValue = meta.method.toLowerCase();
        } else if (field === "http.user_agent") {
            actualValue = meta.userAgent.toLowerCase();
        }

        if (operator === "eq") {
            conditionMatch = (actualValue === targetValue);
        } else if (operator === "ne") {
            conditionMatch = (actualValue !== targetValue);
        } else if (operator === "contains") {
            conditionMatch = actualValue.includes(targetValue);
        } else if (operator === "not_contains") {
            conditionMatch = !actualValue.includes(targetValue);
        } else if (operator === "starts_with") {
            conditionMatch = actualValue.startsWith(targetValue);
        } else if (operator === "in") {
            const list = targetValue.split(",").map((v: string) => v.trim());
            if (field === "ip.src") {
                 conditionMatch = list.some((target: string) => target.includes("/") ? isIpInCidr(meta.ip, target) : meta.ip === target);
            } else {
                 conditionMatch = list.includes(actualValue);
            }
        } else if (operator === "not_in") {
            const list = targetValue.split(",").map((v: string) => v.trim());
            if (field === "ip.src") {
                 conditionMatch = !list.some((target: string) => target.includes("/") ? isIpInCidr(meta.ip, target) : meta.ip === target);
            } else {
                 conditionMatch = !list.includes(actualValue);
            }
        }
      } catch (e) {
         // Silently ignore corrupted rules so they don't break the worker flow
      }

      if (conditionMatch) {
         const isBlock = rule.action === "block";
         const isChallenge = rule.action === "challenge" || rule.action === "managed_challenge" || rule.action === "js_challenge";
         const isAllow = rule.action === "allow" || rule.action === "bypass";
         
         const logAction = isBlock ? "hard_block" : (isChallenge ? (session ? "session_created" : "challenge_issued") : "session_created");
         
         ctx.waitUntil(
            logSecurityEvent(
               env,
               logAction as any,
               meta,
               isBlock ? 100 : 0,
               null,
               `Custom FW rule: ${rule.description || ("Action " + rule.action)}${isChallenge && session ? ' (Bypassed via session)' : ''}`
            )
         );

         if (isBlock) {
             return handleHardBlock(env);
         } else if (isAllow) {
            return fetchWithInjectors(request, meta, env, true);
         } else if (isChallenge) {
             if (session) {
                 continue; // They already solved a challenge, proceed to next rule
             }
             return serveChallengePagePlaceholder(meta, domainConfig, env);
         }
         // if action is "log", we just continue checking next rules.
      }
    }

    // NOTE: Challenge submission POST handler was moved above custom firewall
    // rules and sensitive path checks to prevent broad rules from intercepting
    // the solve request. See the block after domain config validation above.

    // --- KV Session Validation (Flood & Visit Checks) ---
    // If the user has a valid, signed session cookie, they passed CAPTCHA before.
    // We already checked this above, but now we enforce visit counter + flood protection
    // to prevent post-CAPTCHA abuse.
    if (session) {
      // Skip static assets — no need to count CSS/JS/images
      if (isStaticAssetPath(meta.path)) return fetch(request);

      if (!meta.isPrefetch) {
        // Check grace marker first (set by challenge.ts after successful CAPTCHA solve).
        // During the 2-minute grace window, the user just proved they're human.
        const uaHash = await visitCounterUAHash(meta.userAgent);
        const graceKey = `vc_grace:${meta.ip}:${uaHash}`;
        let hasGrace = false;
        try {
          hasGrace = (await env.SESSION_KV.get(graceKey)) === "1";
        } catch {}

        // Layer 1: Flood protection (catches burst attacks — rapid requests)
        // Skip counter increment during grace — user just proved human.
        // Without this, normal browsing during grace accumulates counters
        // that immediately trigger hard-block when grace expires.
        if (!hasGrace) {
          ctx.waitUntil(incrementFloodCounters(meta.ip, meta.userAgent, env.SESSION_KV));
        }
        const floodStatus = await getFloodStatus(meta.ip, meta.userAgent, env.SESSION_KV, thresholds);
        if (floodStatus.action !== "pass") {
          const floodDetails = `Post-session flood ${floodStatus.action} (burst=${floodStatus.burst}/15s, sustained=${floodStatus.sustained}/60s, grace=${hasGrace})`;

          if (hasGrace) {
            // Grace window active — user JUST proved human.
            // Only enforce hard-block level floods (extreme abuse).
            // Skip "challenge" level to avoid CAPTCHA loop caused by
            // flood counters persisting from pre-solve requests.
            if (floodStatus.action === "block") {
              try {
                await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
                  expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
                });
              } catch {}
              ctx.waitUntil(logSecurityEvent(env, "flood_blocked" as any, meta, 90, null, floodDetails));
              return handleHardBlock(env);
            }
            // "challenge" level during grace → pass through (user just proved human)
          } else {
            // No grace — session holder continuing to flood = confirmed abuse.
            // Escalate ALL flood triggers to hard block (no more CAPTCHAs).
            // They already proved human once; continued flooding = bot/abuse.
            try {
              await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
                expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
              });
            } catch {}
            ctx.waitUntil(logSecurityEvent(env, "flood_blocked" as any, meta, 90, null,
              `Post-CAPTCHA abuse: ${floodDetails} — 24h ban applied`));
            return handleHardBlock(env);
          }
        }

        // Layer 2: Visit counter (catches slow attacks — many pages over time)
        if (!hasGrace) {
          const visitCount = await checkAndIncrementVisitCounter(meta, env);
          const allowUserAgentBasedBots = String(env.ES_ALLOW_UA_CRAWLER_ALLOWLIST || "").toLowerCase();
          if (visitCount >= thresholds.visit_captcha_threshold && allowUserAgentBasedBots !== "on") {
            // Session holder exceeded visit threshold outside grace window.
            // A legitimate user would never hit 6 pages in 3 minutes after proving human.
            // Hard ban for 24 hours.
            try {
              await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
                expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
              });
            } catch {}
            ctx.waitUntil(
              logSecurityEvent(
                env,
                "hard_block",
                meta,
                95,
                null,
                `Post-CAPTCHA abuse: session holder exceeded visit threshold (${visitCount}/${thresholds.visit_captcha_threshold} in ${VISIT_COUNTER_TTL}s) — 24h ban applied`
              )
            );
            return handleHardBlock(env);
          }
        }
      }
      return fetchWithInjectors(request, meta, env, true);
    }

    // Do not issue interactive challenges for static assets.
    if (isStaticAssetPath(meta.path)) {
      return fetch(request);
    }

    // --- Trusted IP Path: Visit Counter + Flood Read-Only ---
    // Trusted IPs skip expensive risk engine but are still monitored.
    // Visit counter catches slow attacks (6 pages/3 min).
    // Daily Visit counter catches extremely slow "drip" attacks over 24 hours.
    // Flood read-only catches burst attacks during trust-transition window.
    if (isTrustedIP && !meta.isPrefetch) {
      // Layer 1.5: Daily Visit Counter
      const dailyVisits = await checkAndIncrementDailyVisitCounter(meta, domain, env);
      if (dailyVisits > thresholds.daily_visit_limit) {
        try { await env.SESSION_KV.delete(getTrustedIpKey(domain, meta.ip)); } catch {}
        try {
          await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
            expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
          });
        } catch {}
        ctx.waitUntil(
          logSecurityEvent(
            env, "hard_block", meta, 95, null,
            `Trusted IP exceeded daily visit limit (${dailyVisits}/${thresholds.daily_visit_limit} in 24h) — 24h ban applied`
          )
        );
        return handleHardBlock(env);
      }

      // Layer 1.7: ASN Hourly Counter
      if (meta.asn) {
        const asnVisits = await checkAndIncrementASNVisitCounter(meta.asn, domain, env);
        if (asnVisits > thresholds.asn_hourly_visit_limit) {
          try { await env.SESSION_KV.delete(getTrustedIpKey(domain, meta.ip)); } catch {}
          ctx.waitUntil(
            logSecurityEvent(env, "challenge_issued" as any, meta, 50, null, `Trusted IP ASN (${meta.asn}) exceeded hourly limit (${asnVisits}/${thresholds.asn_hourly_visit_limit}) — trust revoked`)
          );
          return serveChallengePagePlaceholder(meta, domainConfig, env);
        }
      }

      // Layer 1: Visit counter (1 read + 1 write)
      const visitCount = await checkAndIncrementVisitCounter(meta, env);
      const allowUserAgentBasedBots = String(env.ES_ALLOW_UA_CRAWLER_ALLOWLIST || "").toLowerCase();
      if (visitCount >= thresholds.visit_captcha_threshold && allowUserAgentBasedBots !== "on") {
        // Invalidate trust — this IP is suspicious
        try {
          await env.SESSION_KV.delete(getTrustedIpKey(domain, meta.ip));
        } catch {}
        ctx.waitUntil(
          logSecurityEvent(
            env,
            "challenge_issued",
            meta,
            50,
            null,
            `Trusted IP exceeded visit threshold (${visitCount}/${thresholds.visit_captcha_threshold} in ${VISIT_COUNTER_TTL}s) — trust revoked`
          )
        );
        return serveChallengePagePlaceholder(meta, domainConfig, env);
      }

      // Layer 2: Flood read-only (5 reads, 0 writes — free safety net)
      // Counters may still be alive from the pre-trust phase (~60s overlap)
      const floodStatus = await getFloodStatus(meta.ip, meta.userAgent, env.SESSION_KV, thresholds);
      if (floodStatus.action !== "pass") {
        // Burst detected — invalidate trust
        try {
          await env.SESSION_KV.delete(getTrustedIpKey(domain, meta.ip));
        } catch {}
        ctx.waitUntil(
          logSecurityEvent(
            env,
            floodStatus.action === "block" ? "flood_blocked" as any : "flood_challenged" as any,
            meta,
            floodStatus.action === "block" ? 90 : 55,
            null,
            `Flood detected on trusted IP (burst=${floodStatus.burst}, sustained=${floodStatus.sustained}) — trust revoked`
          )
        );
        if (floodStatus.action === "block") {
          try {
            await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
              expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
            });
          } catch {}
          return handleHardBlock(env);
        }
        return serveChallengePagePlaceholder(meta, domainConfig, env);
      }

      // Both layers passed — trusted, continue to origin
      return fetchWithInjectors(request, meta, env, true);
    }

    // --- Flood Protection (Full Read+Write — new visitors only) ---
    // Uses composite IP + UA hash to avoid punishing shared-NAT users.
    // Two thresholds: burst (15s) and sustained (60s).
    if (!meta.isPrefetch) {
      // Increment counters in the background (non-blocking)
      ctx.waitUntil(incrementFloodCounters(meta.ip, meta.userAgent, env.SESSION_KV));

      const floodStatus = await getFloodStatus(meta.ip, meta.userAgent, env.SESSION_KV, thresholds);
      const floodMode = getSecurityMode(domainConfig);

      if (floodStatus.action !== "pass") {
        const referrer = request.headers.get("Referer") || "none";
        const floodDetails = `Flood ${floodStatus.action} (window=${floodStatus.triggerWindow}, burst=${floodStatus.burst}/15s, sustained=${floodStatus.sustained}/60s, ipBurst=${floodStatus.ipBurst}, ipSustained=${floodStatus.ipSustained}, uaEntropy=${floodStatus.uaEntropy}, path=${meta.path}, referrer=${referrer}, UA=${meta.userAgent.slice(0, 120)})`;

        if (floodMode === "monitor") {
          // Monitor mode: log only, do not enforce
          ctx.waitUntil(
            logSecurityEvent(
              env,
              "flood_monitor" as any,
              meta,
              floodStatus.action === "block" ? 90 : 55,
              null,
              floodDetails
            )
          );
        } else if (floodStatus.action === "block") {
          // Block: temp-ban + hard block
          try {
            await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
              expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
            });
          } catch {}
          ctx.waitUntil(
            logSecurityEvent(
              env,
              "flood_blocked" as any,
              meta,
              90,
              null,
              floodDetails
            )
          );
          return handleHardBlock(env);
        } else {
          // Challenge
          ctx.waitUntil(
            logSecurityEvent(
              env,
              "flood_challenged" as any,
              meta,
              55,
              null,
              floodDetails
            )
          );
          return serveChallengePagePlaceholder(meta, domainConfig, env);
        }
      }
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
        await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
          expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
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

    // Do not issue interactive challenges for static assets.
    if (isStaticAssetPath(meta.path)) {
      return fetch(request);
    }

    // --- Visit Counter for New Visitors (before risk engine) ---
    if (!meta.isPrefetch) {
      const visitCount = await checkAndIncrementVisitCounter(meta, env);
      const allowUserAgentBasedBots = String(env.ES_ALLOW_UA_CRAWLER_ALLOWLIST || "").toLowerCase();
      if (visitCount >= thresholds.visit_captcha_threshold && allowUserAgentBasedBots !== "on") {
        ctx.waitUntil(
          logSecurityEvent(
            env,
            "challenge_issued",
            meta,
            45,
            null,
            `New visitor exceeded visit threshold (${visitCount}/${thresholds.visit_captcha_threshold} in ${VISIT_COUNTER_TTL}s)`
          )
        );
        return serveChallengePagePlaceholder(meta, domainConfig, env);
      }
    }

    // --- Pending Challenge Failures Check ---
    // If this IP has ANY recent CAPTCHA failures, force re-challenge.
    // Without this, a borderline risk score could flip from SUSPICIOUS to NORMAL
    // between requests, allowing the user through after a failed solve.
    try {
      const failCount = await env.SESSION_KV.get(`failrate:${meta.ip}`);
      if (failCount && parseInt(failCount, 10) > 0) {
        ctx.waitUntil(
          logSecurityEvent(
            env,
            "challenge_issued",
            meta,
            55,
            null,
            `Re-challenge: IP has ${failCount} recent CAPTCHA failure(s)`
          )
        );
        return serveChallengePagePlaceholder(meta, domainConfig, env);
      }
    } catch {}

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
        // Score 0-30: Transparent pass.
        // Dynamic sampling: log only a % of pass events to reduce D1 pressure.
        ctx.waitUntil(
          samplePassLog(
            env,
            meta,
            risk.score,
            `Normal risk — transparent pass (mode=${securityMode})`
          )
        );

        // Slow-drip protection: Check daily limit before granting a completely free pass
        if (!meta.isPrefetch) {
          const dailyVisits = await checkAndIncrementDailyVisitCounter(meta, domain, env);
          if (dailyVisits > thresholds.daily_visit_limit) {
            try {
              await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
                expirationTtl: thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS,
              });
            } catch {}
            ctx.waitUntil(logSecurityEvent(env, "hard_block", meta, 95, null, `Low-risk IP exceeded daily visit limit (${dailyVisits}/${thresholds.daily_visit_limit} in 24h) — 24h ban applied`));
            return handleHardBlock(env);
          }

          if (meta.asn) {
            const asnVisits = await checkAndIncrementASNVisitCounter(meta.asn, domain, env);
            if (asnVisits > thresholds.asn_hourly_visit_limit) {
              ctx.waitUntil(logSecurityEvent(env, "challenge_issued" as any, meta, 50, null, `Low-risk IP ASN (${meta.asn}) exceeded hourly limit (${asnVisits}/${thresholds.asn_hourly_visit_limit})`));
              return serveChallengePagePlaceholder(meta, domainConfig, env);
            }
          }
        }

        // Cache this IP as trusted if very low risk — subsequent requests
        // will skip the risk engine but flood protection remains active.
        if (risk.score <= 15) {
          ctx.waitUntil(
            env.SESSION_KV.put(
              getTrustedIpKey(domain, meta.ip),
              "1",
              { expirationTtl: TRUSTED_IP_TTL_SECONDS }
            ).catch(() => {})
          );
        }
        return fetchWithInjectors(request, meta, env, true);
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
            const actualBanTtl = thresholds.temp_ban_ttl_seconds || TEMP_BAN_TTL_SECONDS;
            await env.SESSION_KV.put(getTempBanKey(domain, meta.ip), "1", {
              expirationTtl: actualBanTtl,
            });
            ctx.waitUntil(
              logSecurityEvent(
                env,
                "hard_block",
                meta,
                risk.score,
                null,
                `Auto-banned by malicious signature (${actualBanTtl}s window)`
              )
            );
            // IP Farm: Malicious auto-ban → permanent ban
            ctx.waitUntil(markForIpFarm(meta.ip, env, meta, `Malicious risk score ${risk.score} — factors: ${risk.factors.join("; ")}`));
          } catch {
            // KV failures are non-fatal
          }
        }

        // Trigger async AI defense only when attack indicators are strong.
        if (shouldTriggerAIDefense(risk, securityMode)) {
          ctx.waitUntil(triggerAIDefenseIfReady(env, meta));
        }

        // Track ASN daily attack reputation
        if (meta.asn) {
           ctx.waitUntil((async () => {
              try {
                const asnAttackKey = `attack:asn:day:${new Date().toISOString().slice(0, 10)}:${meta.asn}`;
                const currentStr = await env.SESSION_KV.get(asnAttackKey);
                const current = currentStr ? parseInt(currentStr, 10) : 0;
                await env.SESSION_KV.put(asnAttackKey, String(current + 1), { expirationTtl: 86400 });
              } catch {}
           })());
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
    if (await isAICooldownLikelyActive(env)) {
      return;
    }
    const { triggerAIDefense } = await import("./ai-defense");
    await triggerAIDefense(env, meta);
  } catch {
    // Phase 5 not yet implemented — silently skip
  }
}

async function isAICooldownLikelyActive(env: Env): Promise<boolean> {
  try {
    const lastRun = await env.SESSION_KV.get(AI_LAST_RUN_KEY);
    if (!lastRun) return false;
    const elapsed = Date.now() - parseInt(lastRun, 10);
    if (!Number.isFinite(elapsed)) return false;
    return elapsed < AI_COOLDOWN_SECONDS * 1000;
  } catch {
    return false;
  }
}

class HoneypotInjector {
  constructor(private paths: string[]) {}

  element(el: Element) {
    // Multi-Trap Strategy with varying stealth techniques
    // Removed left:-9999px to prevent layout shift (pulling page to the left)
    const trap1 = `<a href="${this.paths[0]}" aria-hidden="true" tabindex="-1" style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0); opacity:0; pointer-events:none; z-index:-999;" rel="nofollow">API Metrics</a>`;
    const trap2 = `<a href="${this.paths[1]}" aria-hidden="true" tabindex="-1" style="display:none; visibility:hidden;" rel="nofollow">Fallback Style</a>`;
    const trap3 = `<a href="${this.paths[2]}" aria-hidden="true" tabindex="-1" style="position:absolute; width:0; height:0; overflow:hidden; opacity:0; pointer-events:none; z-index:-999;" rel="nofollow">Legacy Script</a>`;
    
    el.append(trap1 + trap2 + trap3, { html: true });
  }
}

class AnalyzerInjector {
  element(el: Element) {
    const script = `
      <script>
        (function() {
          // Ensure it's running inside the Analyzer Iframe
          if (window.self === window.top) return;
          
          window.addEventListener('load', () => {
            // Give SPAs 2.5 seconds to do initial data fetching
            setTimeout(() => {
              try {
                const resources = performance.getEntriesByType('resource');
                let apiCount = 0;
                
                for (const r of resources) {
                  // Count fetch/xhr occurring on same domain
                  if ((r.initiatorType === 'fetch' || r.initiatorType === 'xmlhttprequest') && r.name.startsWith(window.location.origin)) {
                    apiCount++;
                  }
                }
                
                window.parent.postMessage({
                  type: 'ES_ANALYZE_RESULT',
                  apiCount: apiCount
                }, '*');
              } catch (e) {
                console.error('ES Analyzer execution error', e);
              }
            }, 2500);
          });
        })();
      </script>
    `;
    el.append(script, { html: true });
  }
}

async function fetchWithInjectors(request: Request, meta: RequestMeta, env: Env, injectHoneypot: boolean = true): Promise<Response> {
  let response = await fetch(request);
  if (response.status === 200 && !meta.verifiedBot) {
    const contentType = response.headers.get("content-type") || "";
    if (contentType.includes("text/html")) {
      const url = new URL(request.url);
      const isAnalyzer = url.searchParams.get("es_analyzer") === "1";

      if (isAnalyzer) {
        // Remove frame restrictions so the dashboard iframe can load it
        const newHeaders = new Headers(response.headers);
        newHeaders.delete("X-Frame-Options");
        newHeaders.delete("Content-Security-Policy");
        response = new Response(response.body, {
          status: response.status,
          statusText: response.statusText,
          headers: newHeaders
        });
      }

      let rewriter = new HTMLRewriter();
      let hasRewriten = false;

      if (injectHoneypot) {
        const paths = await getDailyHoneypotPaths(env);
        rewriter = rewriter.on("body", new HoneypotInjector(paths));
        hasRewriten = true;
      }

      if (isAnalyzer) {
        rewriter = rewriter.on("body", new AnalyzerInjector());
        hasRewriten = true;
      }

      if (hasRewriten) {
        response = rewriter.transform(response);
      }
    }
  }
  return response;
}

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------

export default worker;
