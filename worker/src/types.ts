// ============================================================================
// Ultimate Edge Shield — Shared Type Definitions
// All interfaces, enums, and type aliases used across the worker modules.
// ============================================================================

// ---------------------------------------------------------------------------
// Worker Environment Bindings
// Maps directly to wrangler.toml bindings and secrets.
// ---------------------------------------------------------------------------
export interface Env {
  // Cloudflare D1 — persistent storage for challenges, fingerprints, logs, domain configs
  DB: D1Database;

  // Cloudflare KV — sub-millisecond session validation and nonce tracking
  SESSION_KV: KVNamespace;

  // ---- Global Secrets (shared across all tenants) ----

  // Cryptographic signing key for Human Session Tokens (JWT)
  JWT_SECRET: string;

  // Cloudflare API token (WAF rule write + Turnstile widget management)
  CF_API_TOKEN: string;

  // OpenRouter API key for AI-driven threat analysis
  OPENROUTER_API_KEY: string;

  // OpenRouter model identifier (configured in wrangler.toml [vars])
  OPENROUTER_MODEL: string;
  // Optional comma-separated fallback models for OpenRouter.
  OPENROUTER_FALLBACK_MODELS?: string;

  // Optional flag to allow __es_test endpoints on production routes.
  // Set to "on" only during controlled testing windows.
  ES_TEST_MODE?: string;
  // Admin token for secure internal management endpoints (/es-admin/*).
  ES_ADMIN_TOKEN?: string;
  // Optional comma-separated admin source IPs/CIDRs allowed to call /es-admin/*.
  // Example: "203.0.113.10,198.51.100.0/24"
  ES_ADMIN_ALLOWED_IPS?: string;
  // Optional per-IP admin API requests/minute (defaults to 60).
  ES_ADMIN_RATE_LIMIT_PER_MIN?: string;
  // Optional compatibility mode for crawler allow-listing by User-Agent only.
  // Default should remain OFF; enabling this may allow spoofed crawler UAs.
  ES_ALLOW_UA_CRAWLER_ALLOWLIST?: string;
  // Optional strict crawler verification by reverse DNS + forward-confirmed lookup.
  // "on" => known crawler UA must pass IP ownership verification before allow-listing.
  // "off" => rely on verifiedBot and optional UA compatibility mode only.
  ES_CRAWLER_RDNS_VERIFY?: string;
  // Optional flag to disable automatic Cloudflare WAF rule deployment by AI.
  ES_DISABLE_WAF_AUTODEPLOY?: string;
  // Optional strict mode for Turnstile verification on slider submit.
  // "on" => reject when Turnstile token is missing/invalid.
  // "off" => allow soft-pass if slider+telemetry checks pass.
  ES_TURNSTILE_STRICT?: string;
  // Optional strict mode for challenge context binding (cookie/IP).
  // "on" => reject on cookie/IP mismatch.
  // "off" => log mismatch but continue verification.
  ES_STRICT_CONTEXT_BINDING?: string;
  // Optional URL used to redirect requests that hit final hard-block policy.
  // Example: "https://example.com/blocked"
  ES_BLOCK_REDIRECT_URL?: string;

  // ---- Domain-Specific Config ----
  // Turnstile keys, Zone IDs are resolved per-request from D1 `domain_configs`.
  // See DomainConfigRecord type below.
}

// ---------------------------------------------------------------------------
// Risk Assessment
// ---------------------------------------------------------------------------

/** Three-tier risk classification */
export enum RiskLevel {
  NORMAL = "normal",         // Score < 30  — transparent pass
  SUSPICIOUS = "suspicious", // Score 31-70 — slider CAPTCHA + Turnstile
  MALICIOUS = "malicious",   // Score > 70  — hard block at edge
}

/** Result of the behavioral risk scoring engine */
export interface RiskAssessment {
  score: number;
  level: RiskLevel;
  factors: string[];       // Human-readable reasons contributing to the score
  ip: string;
  asn: string | null;
  country: string | null;
  userAgent: string;
  botScore: number | null; // Cloudflare Bot Management score (if available)
}

// ---------------------------------------------------------------------------
// D1 Record Types
// Mirror the schema.sql table structures for type-safe database operations.
// ---------------------------------------------------------------------------

/** Row in the `challenges` table */
export interface ChallengeRecord {
  id: number;
  nonce: string;
  target_x: number;
  ip_address: string;
  fingerprint_hash: string | null;
  user_agent: string | null;
  status: "pending" | "solved" | "failed" | "expired";
  created_at: string;
  expires_at: string;
  solved_at: string | null;
}

/** Row in the `fingerprints` table */
export interface FingerprintRecord {
  hash: string;
  ip_address: string;
  asn: string | null;
  country: string | null;
  user_agent: string | null;
  first_seen: string;
  last_seen: string;
  risk_score: number;
  challenge_count: number;
  fail_count: number;
}

/** Row in the `security_logs` table */
export interface SecurityLogRecord {
  id: number;
  domain_name: string | null;
  event_type: SecurityEventType;
  ip_address: string;
  asn: string | null;
  country: string | null;
  target_path: string | null;
  fingerprint_hash: string | null;
  risk_score: number | null;
  details: string | null;
  created_at: string;
}

/** Row in the `domain_configs` table (Multi-Tenancy) */
export interface DomainConfigRecord {
  domain_name: string;
  zone_id: string;
  turnstile_sitekey: string;
  turnstile_secret: string;
  force_captcha: number;
  security_mode?: "monitor" | "balanced" | "aggressive";
  status: "active" | "paused" | "revoked";
  thresholds_json?: string | null;
  created_at: string;
}

/** Parsed dynamic security thresholds per domain */
export interface DomainThresholds {
  visit_captcha_threshold: number;
  daily_visit_limit: number;
  asn_hourly_visit_limit: number;
  ad_traffic_strict_mode: boolean;
  flood_burst_challenge: number;
  flood_burst_block: number;
  flood_sustained_challenge: number;
  flood_sustained_block: number;
  ip_hard_ban_rate: number;
  max_challenge_failures: number;
  temp_ban_ttl_seconds: number;
  ai_rule_ttl_seconds: number;
  session_ttl_seconds: number;
  auto_aggr_pressure_seconds: number;
  auto_aggr_active_seconds: number;
  auto_aggr_trigger_subnets: number;
  /** Minimum solve time in ms — rejects instant bot solves (balanced: 150, aggressive: 200) */
  challenge_min_solve_ms: number;
  /** Minimum telemetry data points for valid human interaction (balanced: 3, aggressive: 4) */
  challenge_min_telemetry_points: number;
  /** Acceptable pixel tolerance for slider X vs target (balanced: 24, aggressive: 24) */
  challenge_x_tolerance: number;
}

/** Row in the `ip_access_rules` table */
export interface IpAccessRuleRecord {
  id: number;
  domain_name: string;
  ip_or_cidr: string;
  action: "allow" | "block";
  note: string | null;
  created_at: string;
}

/** Row in the `custom_firewall_rules` table */
export interface CustomFirewallRuleRecord {
  id: number;
  domain_name: string;
  description: string | null;
  action: "block" | "challenge" | "js_challenge" | "managed_challenge" | "log" | "allow" | "bypass";
  expression_json: string;
  paused: number;
  expires_at: number | null;
  created_at: string;
  updated_at: string;
}

/** Allowed security event types (enforced at application level) */
export type SecurityEventType =
  | "challenge_issued"
  | "challenge_solved"
  | "challenge_failed"
  | "challenge_warning"
  | "hard_block"
  | "session_created"
  | "session_rejected"
  | "waf_rule_created"
  | "turnstile_failed"
  | "replay_detected";

// ---------------------------------------------------------------------------
// Challenge & Session Types
// ---------------------------------------------------------------------------

/** Payload signed and sent to the client when issuing a challenge */
export interface ChallengePayload {
  nonce: string;
  /** Dynamic submission endpoint (signed per session) */
  submitPath: string;
  /** Turnstile site key for the frontend widget */
  siteKey: string;
  /** Challenge issuance timestamp (Unix ms) */
  issuedAt: number;
  /** Challenge expiry timestamp (Unix ms) */
  expiresAt: number;
  /** HMAC signature of the payload */
  signature: string;
}

/** Client submission when solving the slider challenge */
export interface ChallengeSubmission {
  nonce: string;
  /** Mouse/touch telemetry: array of [x, y, timestamp] tuples */
  telemetry: Array<[number, number, number]>;
  /** Final slider X position submitted by the client */
  sliderX: number;
  /** Device fingerprint hash computed client-side */
  fingerprint: string;
  /** Cloudflare Turnstile response token (may be empty when blocked client-side) */
  turnstileToken?: string;
  /** HMAC signature of the submission payload */
  signature: string;
  /** Original requested path before challenge redirect */
  originalPath?: string;
}

/** JWT claims for the Human Session Token */
export interface SessionTokenClaims {
  /** Subject: fingerprint hash */
  sub: string;
  /** Issuer */
  iss: string;
  /** Issued at (Unix seconds) */
  iat: number;
  /** Expiration (Unix seconds) */
  exp: number;
  /** Bound IP address */
  ip: string;
  /** Bound fingerprint hash */
  fph: string;
  /** Session risk score at issuance */
  rsk: number;
}

// ---------------------------------------------------------------------------
// AI Defense Types
// ---------------------------------------------------------------------------

/** Structured threat analysis request sent to OpenRouter */
export interface ThreatAnalysisRequest {
  recentLogs: SecurityLogRecord[];
  windowMinutes: number;
  uniqueIPs: number;
  uniqueASNs: number;
  uniqueFingerprints: number;
  failureRate: number;
  topTargetPaths: Array<{ path: string; count: number }>;
}

/** Expected JSON response from OpenRouter threat analysis */
export interface ThreatAnalysisResponse {
  isThreat: boolean;
  confidence: number;        // 0.0 - 1.0
  threatType: string;        // e.g., "credential_stuffing", "ddos", "scraping"
  recommendedAction: "block_ip" | "block_asn" | "block_country" | "rate_limit" | "monitor";
  targets: string[];         // IPs, ASNs, or countries to act on
  reasoning: string;         // Human-readable explanation
  wafExpression?: string;    // Optional: suggested Cloudflare WAF expression
}

/** Cloudflare WAF rule creation payload */
export interface WAFRulePayload {
  description: string;
  expression: string;
  action: "block" | "challenge" | "managed_challenge";
}

// ---------------------------------------------------------------------------
// Request Metadata (extracted from request.cf)
// ---------------------------------------------------------------------------

/** Normalized request metadata from Cloudflare edge */
export interface RequestMeta {
  ip: string;
  asn: string | null;
  country: string | null;
  city: string | null;
  continent: string | null;
  colo: string | null;
  isEU: boolean;
  httpProtocol: string | null;
  tlsVersion: string | null;
  tlsCipher: string | null;
  botManagementScore: number | null;
  verifiedBot: boolean;
  userAgent: string;
  method: string;
  path: string;
  url: string;
  acceptLanguage: string | null;
  referer: string | null;
  secFetchSite: string | null;
  secFetchMode: string | null;
  isPrefetch: boolean;
}
