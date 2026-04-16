// ============================================================================
// Ultimate Edge Shield — Request Utilities
// Helpers for parsing Cloudflare request metadata, extracting client IP,
// and safely handling local development environments.
//
// Functions:
//   extractRequestMeta()  — Parses request.cf, headers, and URL
//   extractClientIP()     — Reliable client IP extraction
//   getDomainFromRequest() — Extracts the target domain from Host header
//   createJsonResponse()  — Standardized JSON response builder
//   createErrorResponse() — Standardized error response builder
// ============================================================================

import type { RequestMeta, DomainConfigRecord, DomainThresholds, Env } from "./types";

// ---------------------------------------------------------------------------
// Type assertion for Cloudflare's IncomingRequestCfProperties
// The cf object is loosely typed; we safely extract known properties.
// ---------------------------------------------------------------------------

interface CfProperties {
  asn?: number;
  asOrganization?: string;
  country?: string | null;
  city?: string;
  continent?: string;
  isEUCountry?: string;
  colo?: string;
  httpProtocol?: string;
  tlsVersion?: string;
  tlsCipher?: string;
  botManagement?: {
    score?: number;
    verifiedBot?: boolean;
    corporateProxy?: boolean;
    staticResource?: boolean;
  };
}

// ---------------------------------------------------------------------------
// Public: Client IP Extraction
// ---------------------------------------------------------------------------

/**
 * Extracts the client IP address from the request.
 * Checks Cloudflare-specific headers first, then falls back to
 * standard proxy headers. Returns "127.0.0.1" in local dev mode.
 *
 * Header priority:
 *   1. CF-Connecting-IP (Cloudflare edge — most reliable)
 *   2. X-Real-IP (common reverse proxy header)
 *   3. X-Forwarded-For (first IP in the chain)
 *   4. Fallback: "127.0.0.1" (local development)
 */
export function extractClientIP(request: Request): string {
  // Cloudflare always sets this header at the edge
  const cfIP = request.headers.get("CF-Connecting-IP");
  if (cfIP) return cfIP.trim();

  // Fallback: reverse proxy headers
  const realIP = request.headers.get("X-Real-IP");
  if (realIP) return realIP.trim();

  // Fallback: X-Forwarded-For (take the first/leftmost IP)
  const forwardedFor = request.headers.get("X-Forwarded-For");
  if (forwardedFor) {
    const firstIP = forwardedFor.split(",")[0];
    if (firstIP) return firstIP.trim();
  }

  // Local development fallback
  return "127.0.0.1";
}

// ---------------------------------------------------------------------------
// Public: Request Metadata Extraction
// ---------------------------------------------------------------------------

/**
 * Extracts and normalizes all security-relevant metadata from a request.
 * Safely handles missing request.cf (local Wrangler dev mode) by providing
 * sensible mock defaults so the Worker never crashes.
 *
 * In production (Cloudflare edge), request.cf contains rich metadata
 * including ASN, country, TLS info, and Bot Management scores.
 * In local dev, request.cf is undefined — all fields get safe defaults.
 */
export function extractRequestMeta(request: Request): RequestMeta {
  const url = new URL(request.url);
  const ip = extractClientIP(request);
  const userAgent = request.headers.get("User-Agent") || "unknown";
  const acceptLanguage = request.headers.get("Accept-Language");
  const referer = request.headers.get("Referer");
  const secFetchSite = request.headers.get("Sec-Fetch-Site");
  const secFetchMode = request.headers.get("Sec-Fetch-Mode");
  
  const purpose = request.headers.get("Purpose") || request.headers.get("X-Purpose") || request.headers.get("Sec-Purpose");
  const isPrefetch = purpose === "prefetch" || purpose === "preview";

  // Safely extract the cf object — may be undefined in local dev
  const cf = (request as unknown as { cf?: CfProperties }).cf;

  if (!cf) {
    // Local development mode: return mock metadata
    return {
      ip,
      asn: null,
      country: null,
      city: null,
      continent: null,
      colo: null,
      isEU: false,
      httpProtocol: null,
      tlsVersion: null,
      tlsCipher: null,
      botManagementScore: null,
      verifiedBot: false,
      userAgent,
      method: request.method,
      path: url.pathname,
      url: url.toString(),
      acceptLanguage,
      referer,
      secFetchSite,
      secFetchMode,
      isPrefetch,
    };
  }

  // Production mode: extract from Cloudflare edge metadata
  return {
    ip,
    asn: cf.asn != null ? String(cf.asn) : null,
    country: cf.country ?? null,
    city: cf.city ?? null,
    continent: cf.continent ?? null,
    colo: cf.colo ?? null,
    isEU: cf.isEUCountry === "1",
    httpProtocol: cf.httpProtocol ?? null,
    tlsVersion: cf.tlsVersion ?? null,
    tlsCipher: cf.tlsCipher ?? null,
    botManagementScore: cf.botManagement?.score ?? null,
    verifiedBot: cf.botManagement?.verifiedBot === true,
    userAgent,
    method: request.method,
    path: url.pathname,
    url: url.toString(),
    acceptLanguage,
    referer,
    secFetchSite,
    secFetchMode,
    isPrefetch,
  };
}

// ---------------------------------------------------------------------------
// Public: Domain Extraction
// ---------------------------------------------------------------------------

/**
 * Extracts the target domain from the request's Host header.
 * Strips port numbers and normalizes to lowercase.
 * Used to resolve per-domain config from D1 domain_configs table.
 */
export function getDomainFromRequest(request: Request): string {
  const host = request.headers.get("Host") || new URL(request.url).hostname;
  // Strip port number if present (e.g., "example.com:8787" -> "example.com")
  return host.split(":")[0].toLowerCase();
}

// ---------------------------------------------------------------------------
// Public: Standardized Response Builders
// ---------------------------------------------------------------------------

/**
 * Creates a JSON response with standard security headers.
 * All responses from the Worker use this to ensure consistent
 * security posture and content-type handling.
 */
export function createJsonResponse(
  data: unknown,
  status: number = 200,
  extraHeaders: Record<string, string> = {}
): Response {
  const headers: Record<string, string> = {
    "Content-Type": "application/json; charset=utf-8",
    // Prevent MIME-type sniffing
    "X-Content-Type-Options": "nosniff",
    // Prevent clickjacking
    "X-Frame-Options": "DENY",
    // Strict referrer policy
    "Referrer-Policy": "strict-origin-when-cross-origin",
    // Prevent caching of security-sensitive responses
    "Cache-Control": "no-store, no-cache, must-revalidate, max-age=0",
    "Pragma": "no-cache",
    ...extraHeaders,
  };

  return new Response(JSON.stringify(data), { status, headers });
}

/**
 * Creates a standardized error JSON response.
 * Includes a machine-readable error code and human-readable message.
 * Never leaks internal implementation details.
 */
export function createErrorResponse(
  code: string,
  message: string,
  status: number = 403
): Response {
  return createJsonResponse(
    {
      success: false,
      error: { code, message },
      timestamp: Date.now(),
    },
    status
  );
}

/**
 * Creates an HTML response with security headers.
 * Used for serving the CAPTCHA challenge page.
 */
export function createHtmlResponse(
  html: string,
  status: number = 200,
  extraHeaders: Record<string, string> = {}
): Response {
  const headers: Record<string, string> = {
    "Content-Type": "text/html; charset=utf-8",
    "X-Content-Type-Options": "nosniff",
    "X-Frame-Options": "DENY",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    // Never cache challenge pages to avoid serving stale nonces/UI versions
    "Cache-Control": "no-store, no-cache, must-revalidate, max-age=0",
    "Pragma": "no-cache",
    // CSP: restrictive by default, loosened per-page as needed
    "Content-Security-Policy":
      "default-src 'self'; " +
      "script-src 'self' https://challenges.cloudflare.com 'unsafe-inline'; " +
      "style-src 'self' 'unsafe-inline'; " +
      "frame-src https://challenges.cloudflare.com; " +
      "connect-src 'self'; " +
      "img-src 'self' data:; " +
      "object-src 'none'; " +
      "base-uri 'self';",
    ...extraHeaders,
  };

  return new Response(html, { status, headers });
}

// ---------------------------------------------------------------------------
// Public: Input Sanitization
// ---------------------------------------------------------------------------

/**
 * Sanitizes a string for safe use in logging and error messages.
 * Strips control characters, limits length, and escapes angle brackets.
 */
export function sanitizeInput(input: string, maxLength: number = 256): string {
  return input
    .replace(/[\x00-\x1F\x7F]/g, "")  // Strip control characters
    .replace(/</g, "&lt;")              // Escape HTML
    .replace(/>/g, "&gt;")
    .substring(0, maxLength);
}

/**
 * Validates that a string looks like a valid IPv4 or IPv6 address.
 * Used as a sanity check before storing IPs in D1.
 */
export function isValidIP(ip: string): boolean {
  // IPv4: basic pattern match
  const ipv4Regex = /^(\d{1,3}\.){3}\d{1,3}$/;
  if (ipv4Regex.test(ip)) {
    return ip.split(".").every((octet) => {
      const num = parseInt(octet, 10);
      return num >= 0 && num <= 255;
    });
  }

  // IPv6: contains at least one colon and valid hex characters
  const ipv6Regex = /^[0-9a-fA-F:]+$/;
  return ipv6Regex.test(ip) && ip.includes(":");
}

// ---------------------------------------------------------------------------
// Dynamic Honeypot (Level 3 Anti-Bot)
// ---------------------------------------------------------------------------

export async function getDailyHoneypotPaths(env: { JWT_SECRET: string }): Promise<string[]> {
  const dateStr = new Date().toISOString().slice(0, 10); // "2023-10-25"
  const encoder = new TextEncoder();
  
  // Create a daily varying seed using the secret + date
  const data = encoder.encode(env.JWT_SECRET + dateStr);
  const hashBuffer = await crypto.subtle.digest("SHA-256", data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  const hashHex = hashArray.map((b) => b.toString(16).padStart(2, "0")).join("");
  
  // Use snippets of the hash for unique but stable daily decoy paths.
  const h1 = hashHex.slice(0, 6);
  const h2 = hashHex.slice(6, 12);
  const h3 = hashHex.slice(12, 18);
  
  return [
    `/api/v1/internal-metrics/es-${h1}.json`, // API-like decoy
    `/assets/css/fallback-es-${h2}.css`,      // Asset-like decoy
    `/wp-includes/js/jquery-migrate-es-${h3}.js` // Admin/legacy decoy
  ];
}

// ---------------------------------------------------------------------------
// Public: Configuration Parsing
// ---------------------------------------------------------------------------

/**
 * Mode-aware default challenge thresholds.
 * Automatically selects stricter values for aggressive mode.
 *
 *   balanced / monitor → 150ms, 3 points, 24px  (user-friendly)
 *   aggressive         → 200ms, 4 points, 24px  (bot-hostile)
 *
 * Explicit values in thresholds_json always win over these defaults.
 */
function challengeDefaults(mode: string): Pick<DomainThresholds, 'challenge_min_solve_ms' | 'challenge_min_telemetry_points' | 'challenge_x_tolerance'> {
  if (mode === 'aggressive') {
    return { challenge_min_solve_ms: 200, challenge_min_telemetry_points: 4, challenge_x_tolerance: 24 };
  }
  // balanced / monitor / unknown → permissive defaults
  return { challenge_min_solve_ms: 150, challenge_min_telemetry_points: 3, challenge_x_tolerance: 24 };
}

/**
 * Resolves challenge threshold value from either:
 * 1) legacy scalar number
 * 2) per-mode object: { balanced: number, aggressive: number }
 */
function resolveModeChallengeValue(raw: unknown, mode: 'balanced' | 'aggressive', fallback: number): number {
  if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
    const typed = raw as Record<string, unknown>;
    const perMode = typed[mode];
    if (Number.isFinite(Number(perMode))) {
      return Number(perMode);
    }
    if (Number.isFinite(Number(typed.balanced))) {
      return Number(typed.balanced);
    }
    if (Number.isFinite(Number(typed.aggressive))) {
      return Number(typed.aggressive);
    }
  }
  if (Number.isFinite(Number(raw))) {
    return Number(raw);
  }
  return fallback;
}

/**
 * Parses the dynamic thresholds JSON from the DomainConfigRecord.
 * Provides safe fallback defaults if parsing fails or fields are missing.
 * Challenge thresholds adapt automatically to the domain's security_mode.
 */
export function parseThresholds(config: DomainConfigRecord | null): DomainThresholds {
  const mode = String(config?.security_mode || 'balanced').toLowerCase();
  const modeKey: 'balanced' | 'aggressive' = mode === 'aggressive' ? 'aggressive' : 'balanced';
  const chal = challengeDefaults(mode);

  const defaults: DomainThresholds = {
    visit_captcha_threshold: 6,
    daily_visit_limit: 15,
    asn_hourly_visit_limit: 200,
    ad_traffic_strict_mode: true,
    flood_burst_challenge: 8,
    flood_burst_block: 15,
    flood_sustained_challenge: 8,
    flood_sustained_block: 40,
    ip_hard_ban_rate: 120,
    max_challenge_failures: 8,
    temp_ban_ttl_seconds: 86400,
    ai_rule_ttl_seconds: 604800,
    session_ttl_seconds: 3600,
    auto_aggr_pressure_seconds: 180,
    auto_aggr_active_seconds: 600,
    auto_aggr_trigger_subnets: 8,
    challenge_min_solve_ms: chal.challenge_min_solve_ms,
    challenge_min_telemetry_points: chal.challenge_min_telemetry_points,
    challenge_x_tolerance: chal.challenge_x_tolerance,
  };

  if (!config || !config.thresholds_json) {
    return defaults;
  }

  try {
    const parsed = JSON.parse(config.thresholds_json);
    return {
      visit_captcha_threshold: Number.isFinite(Number(parsed.visit_captcha_threshold)) ? Number(parsed.visit_captcha_threshold) : defaults.visit_captcha_threshold,
      daily_visit_limit: Number.isFinite(Number(parsed.daily_visit_limit)) ? Number(parsed.daily_visit_limit) : defaults.daily_visit_limit,
      asn_hourly_visit_limit: Number.isFinite(Number(parsed.asn_hourly_visit_limit)) ? Number(parsed.asn_hourly_visit_limit) : defaults.asn_hourly_visit_limit,
      ad_traffic_strict_mode: typeof parsed.ad_traffic_strict_mode === 'boolean' ? parsed.ad_traffic_strict_mode : defaults.ad_traffic_strict_mode,
      flood_burst_challenge: Number.isFinite(Number(parsed.flood_burst_challenge)) ? Number(parsed.flood_burst_challenge) : defaults.flood_burst_challenge,
      flood_burst_block: Number.isFinite(Number(parsed.flood_burst_block)) ? Number(parsed.flood_burst_block) : defaults.flood_burst_block,
      flood_sustained_challenge: Number.isFinite(Number(parsed.flood_sustained_challenge)) ? Number(parsed.flood_sustained_challenge) : defaults.flood_sustained_challenge,
      flood_sustained_block: Number.isFinite(Number(parsed.flood_sustained_block)) ? Number(parsed.flood_sustained_block) : defaults.flood_sustained_block,
      ip_hard_ban_rate: Number.isFinite(Number(parsed.ip_hard_ban_rate)) ? Number(parsed.ip_hard_ban_rate) : defaults.ip_hard_ban_rate,
      max_challenge_failures: Number.isFinite(Number(parsed.max_challenge_failures)) ? Number(parsed.max_challenge_failures) : defaults.max_challenge_failures,
      temp_ban_ttl_seconds: Number.isFinite(Number(parsed.temp_ban_ttl_seconds)) ? Number(parsed.temp_ban_ttl_seconds) : defaults.temp_ban_ttl_seconds,
      ai_rule_ttl_seconds: Number.isFinite(Number(parsed.ai_rule_ttl_seconds)) ? Number(parsed.ai_rule_ttl_seconds) : defaults.ai_rule_ttl_seconds,
      session_ttl_seconds: Number.isFinite(Number(parsed.session_ttl_seconds)) ? Number(parsed.session_ttl_seconds) : defaults.session_ttl_seconds,
      auto_aggr_pressure_seconds: Number.isFinite(Number(parsed.auto_aggr_pressure_seconds)) ? Number(parsed.auto_aggr_pressure_seconds) : defaults.auto_aggr_pressure_seconds,
      auto_aggr_active_seconds: Number.isFinite(Number(parsed.auto_aggr_active_seconds)) ? Number(parsed.auto_aggr_active_seconds) : defaults.auto_aggr_active_seconds,
      auto_aggr_trigger_subnets: Number.isFinite(Number(parsed.auto_aggr_trigger_subnets)) ? Number(parsed.auto_aggr_trigger_subnets) : defaults.auto_aggr_trigger_subnets,
      challenge_min_solve_ms: resolveModeChallengeValue(parsed.challenge_min_solve_ms, modeKey, defaults.challenge_min_solve_ms),
      challenge_min_telemetry_points: resolveModeChallengeValue(parsed.challenge_min_telemetry_points, modeKey, defaults.challenge_min_telemetry_points),
      challenge_x_tolerance: resolveModeChallengeValue(parsed.challenge_x_tolerance, modeKey, defaults.challenge_x_tolerance),
    };
  } catch {
    return defaults;
  }
}

/**
 * Detects if a URL indicates traffic originating from paid ad campaigns.
 * Looks for common tracking parameters from Google, Facebook, TikTok, and standardized UTM tags.
 */
export function isAdTraffic(url: string): boolean {
  try {
    const parsedUrl = new URL(url);
    const searchParams = parsedUrl.searchParams;
    return searchParams.has('gclid') ||
           searchParams.has('fbclid') ||
           searchParams.has('ttclid') ||
           searchParams.has('msclkid') ||
           searchParams.has('utm_source') ||
           searchParams.has('utm_medium');
  } catch {
    return false;
  }
}

// ---------------------------------------------------------------------------
// Shared IP / CIDR Utility Functions
// Used by index.ts, challenge.ts, and ai-defense.ts
// ---------------------------------------------------------------------------

export function isIPv4(ip: string): boolean {
  return /^(\d{1,3}\.){3}\d{1,3}$/.test(ip);
}

export function ipv4ToInt(ip: string): number | null {
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

export function isIPv4InCidr(ip: string, cidr: string): boolean {
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

export function ipv6ToBigInt(ip: string): bigint | null {
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

export function isIPv6InCidr(ip: string, cidr: string): boolean {
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

export function isIpInCidr(ip: string, cidr: string): boolean {
  if (cidr.includes(":")) {
    if (!ip.includes(":")) return false;
    return isIPv6InCidr(ip, cidr);
  } else if (cidr.includes(".")) {
    if (!ip.includes(".")) return false;
    return isIPv4InCidr(ip, cidr);
  }
  return false;
}

// ---------------------------------------------------------------------------
// Shared: Extract Domain from Request Metadata
// ---------------------------------------------------------------------------

export function extractDomainFromMeta(meta: RequestMeta): string | null {
  try {
    const host = new URL(meta.url).hostname.trim().toLowerCase();
    return host === "" ? null : host;
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// Shared: IP Allow-List Check (D1 Custom Firewall Rules)
// Checks if an IP matches any 'allow' or 'bypass' rule.
// Used by IP Farm pipelines in both index.ts and challenge.ts.
// ---------------------------------------------------------------------------

export async function isIpAllowListed(ip: string, env: Env): Promise<boolean> {
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

// ---------------------------------------------------------------------------
// Shared: Private IP Check for IP Farm safety
// ---------------------------------------------------------------------------

export function isPrivateOrReservedIP(ip: string): boolean {
  if (!ip || ip === "127.0.0.1" || ip === "::1") return true;
  if (/^10\./.test(ip)) return true;
  if (/^192\.168\./.test(ip)) return true;
  if (/^172\.(1[6-9]|2[0-9]|3[0-1])\./.test(ip)) return true;
  return false;
}
