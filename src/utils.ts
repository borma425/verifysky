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

import type { RequestMeta } from "./types";

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
      userAgent,
      method: request.method,
      path: url.pathname,
      url: url.toString(),
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
    userAgent,
    method: request.method,
    path: url.pathname,
    url: url.toString(),
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
