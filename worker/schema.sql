-- ============================================================================
-- Ultimate Edge Shield — D1 Database Schema
-- Enterprise-Grade Zero-Trust Bot Protection System
--
-- Tables:
--   1. challenges      — CAPTCHA challenge lifecycle (nonce, target, status)
--   2. fingerprints    — Device fingerprint registry with risk tracking
--   3. security_logs   — All security events for AI-driven analysis
--
-- Security Notes:
--   • target_x is NEVER exposed to the client. Stored server-side only.
--   • nonce values are cryptographic random (Web Crypto API), not sequential.
--   • All queries MUST use D1 prepared statements (parameterized) — no raw SQL.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. CHALLENGES TABLE
-- Tracks the full lifecycle of every issued CAPTCHA challenge.
-- Nonces are single-use and expire in 60 seconds.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS challenges (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    nonce            TEXT    NOT NULL UNIQUE,
    target_x         INTEGER NOT NULL,
    ip_address       TEXT    NOT NULL,
    fingerprint_hash TEXT,
    user_agent       TEXT,
    status           TEXT    NOT NULL DEFAULT 'pending',  -- pending | solved | failed | expired
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at       TIMESTAMP NOT NULL,
    solved_at        TIMESTAMP
);

-- Fast nonce lookup for one-time-use validation during challenge submission
CREATE INDEX IF NOT EXISTS idx_challenges_nonce
    ON challenges (nonce);

-- Composite index for querying challenges by IP and status
-- Enables rapid detection of repeated failures from the same source
CREATE INDEX IF NOT EXISTS idx_challenges_ip_status
    ON challenges (ip_address, status);

-- Time-based cleanup of expired challenges
CREATE INDEX IF NOT EXISTS idx_challenges_expires
    ON challenges (expires_at);

-- Fingerprint-based challenge correlation
CREATE INDEX IF NOT EXISTS idx_challenges_fingerprint
    ON challenges (fingerprint_hash);


-- ---------------------------------------------------------------------------
-- 2. FINGERPRINTS TABLE
-- Device fingerprint registry tracking behavioral risk metrics.
-- Each unique device fingerprint gets a single row that accumulates
-- challenge attempts, failures, and an evolving risk score.
--
-- The asn/country columns enable proxy-hopping detection:
-- If the same fingerprint_hash appears from different ASNs within a short
-- window, the device is almost certainly using rotating proxies (bot behavior).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fingerprints (
    hash             TEXT    PRIMARY KEY,
    ip_address       TEXT    NOT NULL,
    asn              TEXT,
    country          TEXT,
    user_agent       TEXT,
    first_seen       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    risk_score       INTEGER   DEFAULT 50,
    challenge_count  INTEGER   DEFAULT 0,
    fail_count       INTEGER   DEFAULT 0
);

-- Fast fingerprint deduplication and lookup
CREATE INDEX IF NOT EXISTS idx_fingerprints_hash
    ON fingerprints (hash);

-- IP-based fingerprint correlation (detect multiple fingerprints from one IP)
CREATE INDEX IF NOT EXISTS idx_fingerprints_ip
    ON fingerprints (ip_address);

-- ASN-based detection for proxy-hopping analysis
CREATE INDEX IF NOT EXISTS idx_fingerprints_asn
    ON fingerprints (asn);

-- Risk score threshold queries (identify high-risk devices)
CREATE INDEX IF NOT EXISTS idx_fingerprints_risk
    ON fingerprints (risk_score);


-- ---------------------------------------------------------------------------
-- 3. SECURITY LOGS TABLE
-- Immutable audit trail of all security events. This is the primary
-- data source for the AI-driven threat analysis pipeline (OpenRouter).
--
-- The target_path column records which route was being attacked, enabling
-- the AI to generate path-specific WAF rules (e.g., block an ASN only
-- on /wp-login.php rather than globally).
--
-- event_type values:
--   challenge_issued   — A CAPTCHA challenge was generated
--   challenge_solved   — A challenge was successfully solved
--   challenge_failed   — A challenge submission was rejected
--   hard_block         — Request blocked at the edge (score > 70)
--   session_created    — Human Session Token issued
--   session_rejected   — Invalid/expired session token detected
--   waf_rule_created   — AI-triggered WAF rule deployed
--   turnstile_failed   — Turnstile verification failed
--   replay_detected    — Nonce reuse attempt detected
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS security_logs (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type       TEXT    NOT NULL,
    ip_address       TEXT    NOT NULL,
    asn              TEXT,
    country          TEXT,
    target_path      TEXT,
    fingerprint_hash TEXT,
    risk_score       INTEGER,
    details          TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Time-range queries for AI batch analysis (most recent events first)
CREATE INDEX IF NOT EXISTS idx_security_logs_created
    ON security_logs (created_at);

-- Composite index for IP + event type correlation
-- Enables rapid pattern detection (e.g., N failures from same IP)
CREATE INDEX IF NOT EXISTS idx_security_logs_ip_event
    ON security_logs (ip_address, event_type);

-- ASN-level pattern detection over time
-- Critical for detecting distributed botnet attacks from the same ASN
CREATE INDEX IF NOT EXISTS idx_security_logs_asn_created
    ON security_logs (asn, created_at);

-- Fingerprint correlation across security events
CREATE INDEX IF NOT EXISTS idx_security_logs_fingerprint
    ON security_logs (fingerprint_hash, created_at);

-- Path-targeted attack detection
-- Identifies concentrated attacks on specific routes (e.g., /checkout, /login)
CREATE INDEX IF NOT EXISTS idx_security_logs_path
    ON security_logs (target_path, created_at);


-- ---------------------------------------------------------------------------
-- 4. DOMAIN CONFIGS TABLE (Multi-Tenancy)
-- Stores per-domain configuration provisioned by the onboarding script.
-- The Worker resolves domain-specific settings (Turnstile keys, Zone ID)
-- from this table at runtime instead of using static Wrangler secrets.
--
-- Global secrets (JWT_SECRET, CF_API_TOKEN, OPENROUTER_API_KEY) remain
-- as Wrangler secrets since they are shared across all tenants.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS domain_configs (
    domain_name       TEXT    PRIMARY KEY,
    zone_id           TEXT    NOT NULL,
    turnstile_sitekey TEXT    NOT NULL,
    turnstile_secret  TEXT    NOT NULL,
    force_captcha     INTEGER NOT NULL DEFAULT 0, -- 0 | 1
    security_mode     TEXT    NOT NULL DEFAULT 'balanced', -- monitor | balanced | aggressive
    status            TEXT    NOT NULL DEFAULT 'active',  -- active | paused | revoked
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Fast domain lookup (PRIMARY KEY already covers this, but explicit for clarity)
CREATE INDEX IF NOT EXISTS idx_domain_configs_status
    ON domain_configs (status);
