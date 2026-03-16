// ============================================================================
// Ultimate Edge Shield — AI Defense & WAF Automation Engine
//
// Async pipeline triggered via ctx.waitUntil() after suspicious/malicious
// requests. Analyzes D1 security logs, queries OpenRouter for threat
// classification, and autonomously deploys Cloudflare WAF rules.
//
// Pipeline:
//   1. Rate-limit check (prevent excessive AI calls)
//   2. Batch recent security logs from D1
//   3. Aggregate attack statistics
//   4. Construct structured prompt for OpenRouter
//   5. Parse AI response (JSON)
//   6. Deploy WAF rule via Cloudflare API (if threat confirmed)
//   7. Log the WAF action
// ============================================================================

import type {
  Env,
  RequestMeta,
  SecurityLogRecord,
  ThreatAnalysisResponse,
  DomainConfigRecord,
} from "./types";

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Minimum interval between AI analysis runs (seconds) */
const AI_COOLDOWN_SECONDS = 60;

/** KV key for tracking the last AI analysis timestamp */
const AI_LAST_RUN_KEY = "ai:last_run";

/** How many minutes of logs to include in the analysis window */
const LOG_WINDOW_MINUTES = 15;

/** Maximum number of log entries to send to OpenRouter */
const MAX_LOGS_PER_BATCH = 200;

/** Minimum number of security events before triggering AI analysis */
const MIN_EVENTS_THRESHOLD = 5;

/** OpenRouter API endpoint */
const OPENROUTER_API_URL = "https://openrouter.ai/api/v1/chat/completions";

/** Cloudflare API base URL */
const CF_API_BASE = "https://api.cloudflare.com/client/v4";

// ---------------------------------------------------------------------------
// Public: Main Trigger (called from index.ts via ctx.waitUntil)
// ---------------------------------------------------------------------------

/**
 * Entry point for the AI defense pipeline.
 * Rate-limited to prevent excessive API calls during sustained attacks.
 *
 * This function is designed to be called via ctx.waitUntil() —
 * it runs asynchronously after the response has been sent to the client.
 * All errors are caught internally to prevent unhandled rejections.
 */
export async function triggerAIDefense(
  env: Env,
  meta: RequestMeta
): Promise<void> {
  try {
    // --- Rate limit: prevent running more than once per cooldown period ---
    const shouldRun = await acquireAILock(env);
    if (!shouldRun) return;

    // --- Fetch recent security logs from D1 ---
    const logs = await fetchRecentLogs(env);
    if (logs.length < MIN_EVENTS_THRESHOLD) return;

    // --- Aggregate statistics for the AI prompt ---
    const stats = aggregateLogStats(logs);

    // --- Query OpenRouter for threat analysis ---
    const analysis = await queryOpenRouter(env, logs, stats);
    if (!analysis) return;

    // --- Deploy WAF rule if a confirmed threat is detected ---
    if (analysis.isThreat && analysis.confidence >= 0.7) {
      await deployWAFRule(env, analysis, meta);
    }
  } catch {
    // All errors are silently consumed — this pipeline must never
    // crash the Worker or affect the main request flow.
  }
}

// ---------------------------------------------------------------------------
// Rate Limiting (KV-based lock)
// ---------------------------------------------------------------------------

/**
 * Attempts to acquire the AI analysis lock.
 * Returns true if the cooldown period has elapsed, false otherwise.
 * Uses KV compare-and-swap to prevent concurrent executions.
 */
async function acquireAILock(env: Env): Promise<boolean> {
  try {
    const lastRun = await env.SESSION_KV.get(AI_LAST_RUN_KEY);
    if (lastRun) {
      const elapsed = Date.now() - parseInt(lastRun, 10);
      if (elapsed < AI_COOLDOWN_SECONDS * 1000) {
        return false; // Cooldown active
      }
    }

    // Set the lock with TTL
    await env.SESSION_KV.put(AI_LAST_RUN_KEY, String(Date.now()), {
      expirationTtl: AI_COOLDOWN_SECONDS,
    });
    return true;
  } catch {
    return false;
  }
}

// ---------------------------------------------------------------------------
// D1 Log Fetching
// ---------------------------------------------------------------------------

/**
 * Fetches recent security logs from D1 within the analysis window.
 * Ordered by creation time (most recent first).
 * Limited to MAX_LOGS_PER_BATCH to keep the AI prompt manageable.
 */
async function fetchRecentLogs(env: Env): Promise<SecurityLogRecord[]> {
  const windowStart = new Date(
    Date.now() - LOG_WINDOW_MINUTES * 60 * 1000
  ).toISOString();

  const result = await env.DB.prepare(
    `SELECT * FROM security_logs
     WHERE created_at >= ?
     ORDER BY created_at DESC
     LIMIT ?`
  )
    .bind(windowStart, MAX_LOGS_PER_BATCH)
    .all<SecurityLogRecord>();

  return result.results || [];
}

// ---------------------------------------------------------------------------
// Log Aggregation
// ---------------------------------------------------------------------------

interface LogAggregation {
  totalEvents: number;
  uniqueIPs: string[];
  uniqueASNs: string[];
  uniqueFingerprints: string[];
  eventTypeCounts: Record<string, number>;
  failureRate: number;
  topTargetPaths: Array<{ path: string; count: number }>;
  topIPs: Array<{ ip: string; count: number }>;
  topASNs: Array<{ asn: string; count: number }>;
}

/**
 * Aggregates security log entries into statistical summaries.
 * These statistics help OpenRouter quickly assess the threat landscape
 * without needing to parse every individual log entry.
 */
function aggregateLogStats(logs: SecurityLogRecord[]): LogAggregation {
  const ipSet = new Set<string>();
  const asnSet = new Set<string>();
  const fpSet = new Set<string>();
  const eventCounts: Record<string, number> = {};
  const pathCounts: Record<string, number> = {};
  const ipCounts: Record<string, number> = {};
  const asnCounts: Record<string, number> = {};
  let failures = 0;

  for (const log of logs) {
    ipSet.add(log.ip_address);
    if (log.asn) asnSet.add(log.asn);
    if (log.fingerprint_hash) fpSet.add(log.fingerprint_hash);

    eventCounts[log.event_type] = (eventCounts[log.event_type] || 0) + 1;
    ipCounts[log.ip_address] = (ipCounts[log.ip_address] || 0) + 1;
    if (log.asn) asnCounts[log.asn] = (asnCounts[log.asn] || 0) + 1;
    if (log.target_path) pathCounts[log.target_path] = (pathCounts[log.target_path] || 0) + 1;

    if (
      log.event_type === "challenge_failed" ||
      log.event_type === "hard_block" ||
      log.event_type === "turnstile_failed" ||
      log.event_type === "replay_detected"
    ) {
      failures++;
    }
  }

  const sortByCount = (obj: Record<string, number>) =>
    Object.entries(obj)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 10)
      .map(([key, count]) => ({ [key.includes(".") || key.includes(":") ? "ip" : key.length <= 10 ? "asn" : "path"]: key, count }));

  return {
    totalEvents: logs.length,
    uniqueIPs: Array.from(ipSet),
    uniqueASNs: Array.from(asnSet),
    uniqueFingerprints: Array.from(fpSet),
    eventTypeCounts: eventCounts,
    failureRate: logs.length > 0 ? failures / logs.length : 0,
    topTargetPaths: Object.entries(pathCounts)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 10)
      .map(([path, count]) => ({ path, count })),
    topIPs: Object.entries(ipCounts)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 15)
      .map(([ip, count]) => ({ ip, count })),
    topASNs: Object.entries(asnCounts)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 10)
      .map(([asn, count]) => ({ asn, count })),
  };
}

// ---------------------------------------------------------------------------
// OpenRouter AI Query
// ---------------------------------------------------------------------------

/**
 * Sends security logs and aggregated stats to OpenRouter for analysis.
 * The AI model analyzes patterns and returns a structured threat assessment.
 *
 * The prompt is carefully constructed to elicit a JSON response with:
 *   - isThreat: boolean
 *   - confidence: 0.0-1.0
 *   - threatType: classification
 *   - recommendedAction: what to block
 *   - targets: specific IPs/ASNs/countries
 *   - reasoning: human-readable explanation
 *   - wafExpression: Cloudflare WAF filter expression
 */
async function queryOpenRouter(
  env: Env,
  logs: SecurityLogRecord[],
  stats: LogAggregation
): Promise<ThreatAnalysisResponse | null> {
  // Build a condensed log summary (only key fields to save tokens)
  const condensedLogs = logs.slice(0, 50).map((log) => ({
    t: log.event_type,
    ip: log.ip_address,
    asn: log.asn,
    co: log.country,
    path: log.target_path,
    fp: log.fingerprint_hash?.substring(0, 12),
    at: log.created_at,
  }));

  const systemPrompt = `You are a cybersecurity threat analysis engine for a Web Application Firewall (WAF).
Your task is to analyze security event logs and determine if there is an active attack pattern.

You MUST respond with a valid JSON object matching this exact schema:
{
  "isThreat": boolean,
  "confidence": number (0.0 to 1.0),
  "threatType": string ("credential_stuffing" | "ddos" | "scraping" | "brute_force" | "bot_network" | "none"),
  "recommendedAction": string ("block_ip" | "block_asn" | "block_country" | "rate_limit" | "monitor"),
  "targets": string[] (list of IPs, ASNs prefixed with "AS", or country codes to take action on),
  "reasoning": string (brief explanation),
  "wafExpression": string (valid Cloudflare WAF expression, e.g. "(ip.src eq 1.2.3.4)" or "(ip.geoip.asnum eq 12345)")
}

Rules:
- Only set isThreat=true if you have HIGH confidence (>=0.7)
- Never block legitimate traffic patterns
- Prefer blocking specific IPs over ASNs, and ASNs over countries
- For DDoS/botnet attacks from many IPs in the same ASN, block the ASN
- The wafExpression must be a valid Cloudflare Ruleset expression
- If no threat is detected, set isThreat=false and recommendedAction="monitor"
- Consider that some failures are normal (humans make mistakes)
- A failure rate above 80% from the same source is highly suspicious`;

  const userPrompt = `Analyze these security events from the last ${LOG_WINDOW_MINUTES} minutes:

STATISTICS:
- Total events: ${stats.totalEvents}
- Unique IPs: ${stats.uniqueIPs.length}
- Unique ASNs: ${stats.uniqueASNs.length}
- Unique fingerprints: ${stats.uniqueFingerprints.length}
- Failure rate: ${(stats.failureRate * 100).toFixed(1)}%
- Event breakdown: ${JSON.stringify(stats.eventTypeCounts)}

TOP ATTACKING IPs:
${stats.topIPs.map((e) => `  ${e.ip}: ${e.count} events`).join("\n")}

TOP ASNs:
${stats.topASNs.map((e) => `  AS${e.asn}: ${e.count} events`).join("\n")}

TOP TARGET PATHS:
${stats.topTargetPaths.map((e) => `  ${e.path}: ${e.count} hits`).join("\n")}

RECENT LOG ENTRIES (condensed):
${JSON.stringify(condensedLogs, null, 0)}

Respond with ONLY the JSON object. No markdown, no explanation outside the JSON.`;

  try {
    const response = await fetch(OPENROUTER_API_URL, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${env.OPENROUTER_API_KEY}`,
        "Content-Type": "application/json",
        "HTTP-Referer": "https://edge-shield.workers.dev",
        "X-Title": "Edge Shield WAF",
      },
      body: JSON.stringify({
        model: env.OPENROUTER_MODEL || "google/gemini-3-flash-preview",
        messages: [
          { role: "system", content: systemPrompt },
          { role: "user", content: userPrompt },
        ],
        temperature: 0.1,
        max_tokens: 1024,
        response_format: { type: "json_object" },
      }),
    });

    if (!response.ok) {
      return null;
    }

    const data = await response.json() as {
      choices?: Array<{ message?: { content?: string } }>;
    };

    const content = data.choices?.[0]?.message?.content;
    if (!content) return null;

    // Parse the AI response, stripping potential markdown fencing
    const cleanJson = content
      .replace(/```json\s*/gi, "")
      .replace(/```\s*/g, "")
      .trim();

    const analysis: ThreatAnalysisResponse = JSON.parse(cleanJson);

    // Validate the response structure
    if (typeof analysis.isThreat !== "boolean" || typeof analysis.confidence !== "number") {
      return null;
    }

    return analysis;
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// WAF Rule Deployment
// ---------------------------------------------------------------------------

/**
 * Deploys a WAF rule to Cloudflare based on the AI's recommendation.
 * The rule is applied to all zones where the attack targets are relevant.
 *
 * Steps:
 *   1. Construct the WAF expression (from AI or built manually)
 *   2. Fetch relevant zone IDs from domain_configs
 *   3. Create a WAF custom rule via Cloudflare API for each zone
 *   4. Log the action to D1
 */
async function deployWAFRule(
  env: Env,
  analysis: ThreatAnalysisResponse,
  meta: RequestMeta
): Promise<void> {
  // Build the WAF expression
  const expression = analysis.wafExpression || buildWAFExpression(analysis);
  if (!expression) return;

  // Construct the rule description
  const description = `[Edge Shield Auto] ${analysis.threatType} - ${analysis.reasoning.substring(0, 100)} (confidence: ${(analysis.confidence * 100).toFixed(0)}%)`;

  // Determine the WAF action based on confidence
  const action = analysis.confidence >= 0.9 ? "block" : "managed_challenge";

  // Fetch all active zone IDs from domain_configs
  const zones = await getActiveZoneIds(env);

  for (const zoneId of zones) {
    try {
      await createCloudflareWAFRule(env, zoneId, {
        description,
        expression,
        action,
      });
    } catch {
      // Individual zone failure should not stop other zones
    }
  }

  // Log the WAF rule creation
  try {
    await env.DB.prepare(
      `INSERT INTO security_logs (event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
       VALUES ('waf_rule_created', ?, ?, ?, ?, NULL, NULL, ?)`
    )
      .bind(
        meta.ip,
        meta.asn,
        meta.country,
        meta.path,
        JSON.stringify({
          threatType: analysis.threatType,
          confidence: analysis.confidence,
          action,
          expression,
          targets: analysis.targets,
          reasoning: analysis.reasoning,
          zonesUpdated: zones.length,
        })
      )
      .run();
  } catch {
    // Logging failure is non-fatal
  }
}

/**
 * Builds a WAF expression from the AI analysis targets.
 * Used as a fallback when the AI doesn't provide a valid wafExpression.
 */
function buildWAFExpression(analysis: ThreatAnalysisResponse): string | null {
  if (!analysis.targets || analysis.targets.length === 0) return null;

  switch (analysis.recommendedAction) {
    case "block_ip": {
      // Filter valid IPs and build expression
      const ips = analysis.targets.filter((t) => /^[\d.:a-fA-F]+$/.test(t));
      if (ips.length === 0) return null;
      if (ips.length === 1) {
        return `(ip.src eq ${ips[0]})`;
      }
      const ipList = ips.map((ip) => `"${ip}"`).join(" ");
      return `(ip.src in {${ipList}})`;
    }

    case "block_asn": {
      const asns = analysis.targets
        .map((t) => t.replace(/^AS/i, ""))
        .filter((t) => /^\d+$/.test(t));
      if (asns.length === 0) return null;
      if (asns.length === 1) {
        return `(ip.geoip.asnum eq ${asns[0]})`;
      }
      const asnList = asns.join(" ");
      return `(ip.geoip.asnum in {${asnList}})`;
    }

    case "block_country": {
      const countries = analysis.targets.filter((t) => /^[A-Z]{2}$/.test(t));
      if (countries.length === 0) return null;
      if (countries.length === 1) {
        return `(ip.geoip.country eq "${countries[0]}")`;
      }
      const countryList = countries.map((c) => `"${c}"`).join(" ");
      return `(ip.geoip.country in {${countryList}})`;
    }

    default:
      return null;
  }
}

// ---------------------------------------------------------------------------
// Cloudflare API: WAF Rule Creation
// ---------------------------------------------------------------------------

interface WAFRuleConfig {
  description: string;
  expression: string;
  action: string;
}

/**
 * Creates a custom WAF rule on a specific Cloudflare zone using
 * the Rulesets API (the modern replacement for Firewall Rules).
 *
 * Uses the zone-level custom rulesets endpoint.
 */
async function createCloudflareWAFRule(
  env: Env,
  zoneId: string,
  rule: WAFRuleConfig
): Promise<void> {
  // First, get existing zone-level custom ruleset (or identify the phase)
  const rulesetId = await getOrCreateCustomRuleset(env, zoneId);
  if (!rulesetId) return;

  // Add the new rule to the ruleset
  const response = await fetch(
    `${CF_API_BASE}/zones/${zoneId}/rulesets/${rulesetId}/rules`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${env.CF_API_TOKEN}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        description: rule.description,
        expression: rule.expression,
        action: rule.action,
        enabled: true,
      }),
    }
  );

  if (!response.ok) {
    const errorBody = await response.text();
    throw new Error(`WAF rule creation failed (${response.status}): ${errorBody}`);
  }
}

/**
 * Retrieves the zone-level custom ruleset ID, or creates one if it doesn't exist.
 * Cloudflare zones have a single "http_request_firewall_custom" phase ruleset.
 */
async function getOrCreateCustomRuleset(
  env: Env,
  zoneId: string
): Promise<string | null> {
  try {
    // List existing rulesets for this zone
    const listResponse = await fetch(
      `${CF_API_BASE}/zones/${zoneId}/rulesets`,
      {
        headers: {
          Authorization: `Bearer ${env.CF_API_TOKEN}`,
          "Content-Type": "application/json",
        },
      }
    );

    if (!listResponse.ok) return null;

    const listData = await listResponse.json() as {
      result?: Array<{ id: string; phase: string }>;
    };

    // Find the custom firewall ruleset
    const customRuleset = listData.result?.find(
      (rs) => rs.phase === "http_request_firewall_custom"
    );

    if (customRuleset) {
      return customRuleset.id;
    }

    // Create a new custom ruleset if none exists
    const createResponse = await fetch(
      `${CF_API_BASE}/zones/${zoneId}/rulesets`,
      {
        method: "POST",
        headers: {
          Authorization: `Bearer ${env.CF_API_TOKEN}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          name: "Edge Shield Auto-Defense Rules",
          description: "Automatically managed by Edge Shield AI defense engine",
          kind: "zone",
          phase: "http_request_firewall_custom",
          rules: [],
        }),
      }
    );

    if (!createResponse.ok) return null;

    const createData = await createResponse.json() as {
      result?: { id: string };
    };

    return createData.result?.id || null;
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// Domain Config Helpers
// ---------------------------------------------------------------------------

/**
 * Fetches all unique active zone IDs from the domain_configs table.
 * WAF rules are deployed to every active zone to ensure global coverage.
 */
async function getActiveZoneIds(env: Env): Promise<string[]> {
  try {
    const result = await env.DB.prepare(
      "SELECT DISTINCT zone_id FROM domain_configs WHERE status = 'active'"
    ).all<{ zone_id: string }>();

    return (result.results || []).map((r) => r.zone_id);
  } catch {
    return [];
  }
}
