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

  // Optional flag to allow __es_test endpoints on production routes.
  // Set to "on" only during controlled testing windows.
  ES_TEST_MODE?: string;
  // Optional flag to disable automatic Cloudflare WAF rule deployment by AI.
  ES_DISABLE_WAF_AUTODEPLOY?: string;

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
  created_at: string;
}

/** Allowed security event types (enforced at application level) */
export type SecurityEventType =
  | "challenge_issued"
  | "challenge_solved"
  | "challenge_failed"
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
}
