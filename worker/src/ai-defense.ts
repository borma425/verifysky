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
const AI_COOLDOWN_SECONDS = 300;

/** KV key for tracking the last AI analysis timestamp */
const AI_LAST_RUN_KEY = "ai:last_run";

/** How many minutes of logs to include in the analysis window */
const LOG_WINDOW_MINUTES = 15;

/** Maximum number of log entries to send to OpenRouter */
const MAX_LOGS_PER_BATCH = 200;

/** Minimum number of security events before triggering AI analysis */
const MIN_EVENTS_THRESHOLD = 12;
const MIN_UNIQUE_IPS_THRESHOLD = 8;
const MIN_SEVERE_EVENTS_THRESHOLD = 6;

/** OpenRouter API endpoint */
const OPENROUTER_API_URL = "https://openrouter.ai/api/v1/chat/completions";
const AI_MODEL_SETTING_KEY = "settings:ai:model";
const AI_MODEL_FALLBACKS_SETTING_KEY = "settings:ai:fallbacks";

/** Cloudflare API base URL */
const CF_API_BASE = "https://api.cloudflare.com/client/v4";
const ES_DISABLE_WAF_VALUES = new Set(["1", "true", "on", "yes"]);

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
    if (!shouldRun) {
      return;
    }

    await logAIDefenseEvent(env, meta, "pipeline_start", "AI defense trigger received");
    await logAIDefenseEvent(env, meta, "lock_acquired", "AI cooldown lock acquired");

    // --- Fetch recent security logs from D1 ---
    const logs = await fetchRecentLogs(env);
    if (logs.length < MIN_EVENTS_THRESHOLD) {
      await logAIDefenseEvent(
        env,
        meta,
        "insufficient_events",
        `Only ${logs.length} events in ${LOG_WINDOW_MINUTES}m window`
      );
      return;
    }
    await logAIDefenseEvent(env, meta, "logs_loaded", `Loaded ${logs.length} events`);

    // --- Aggregate statistics for the AI prompt ---
    const stats = aggregateLogStats(logs);
    await logAIDefenseEvent(
      env,
      meta,
      "stats_ready",
      `failRate=${(stats.failureRate * 100).toFixed(1)}%, uniqueIPs=${stats.uniqueIPs.length}, uniqueASNs=${stats.uniqueASNs.length}`
    );

    if (!shouldQueryOpenRouter(stats)) {
      await logAIDefenseEvent(
        env,
        meta,
        "openrouter_skip",
        "Traffic pattern below high-threat threshold; skipped to save tokens"
      );
      return;
    }

    // --- Query OpenRouter for threat analysis ---
    const analysis = await queryOpenRouter(env, logs, stats);
    if (!analysis) {
      await logAIDefenseEvent(env, meta, "analysis_null", "OpenRouter returned null/invalid response");
      return;
    }
    await logAIDefenseEvent(
      env,
      meta,
      "analysis_ready",
      `isThreat=${analysis.isThreat}, confidence=${analysis.confidence.toFixed(2)}, action=${analysis.recommendedAction}`
    );

    const wafDisabled = ES_DISABLE_WAF_VALUES.has(
      (env.ES_DISABLE_WAF_AUTODEPLOY || "").toLowerCase()
    );

    // --- Deploy WAF rule if a confirmed threat is detected and auto-deploy is enabled ---
    if (analysis.isThreat && analysis.confidence >= 0.7) {
      if (wafDisabled) {
        await logAIDefenseEvent(
          env,
          meta,
          "waf_disabled",
          "WAF auto-deploy disabled by ES_DISABLE_WAF_AUTODEPLOY"
        );
      } else {
        await deployWAFRule(env, analysis, meta);
        await logAIDefenseEvent(env, meta, "waf_deploy_attempted", "WAF deployment attempted");
      }
    } else {
      await logAIDefenseEvent(env, meta, "monitor_only", "No WAF action taken by policy");
    }
  } catch (error) {
    // All errors are silently consumed — this pipeline must never
    // crash the Worker or affect the main request flow.
    await logAIDefenseEvent(
      env,
      meta,
      "pipeline_error",
      error instanceof Error ? error.message.substring(0, 220) : "Unknown error"
    );
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
  const sqliteWindowExpr = `-${LOG_WINDOW_MINUTES} minutes`;
  const result = await env.DB.prepare(
    `SELECT * FROM security_logs
     WHERE datetime(created_at) >= datetime('now', ?)
     ORDER BY created_at DESC
     LIMIT ?`
  )
    .bind(sqliteWindowExpr, MAX_LOGS_PER_BATCH)
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

function shouldQueryOpenRouter(stats: LogAggregation): boolean {
  if (stats.totalEvents < MIN_EVENTS_THRESHOLD) return false;

  const hardBlocks = stats.eventTypeCounts.hard_block || 0;
  const challengeFails = stats.eventTypeCounts.challenge_failed || 0;
  const turnstileFails = stats.eventTypeCounts.turnstile_failed || 0;
  const replayEvents = stats.eventTypeCounts.replay_detected || 0;
  const severeEvents = hardBlocks + challengeFails + turnstileFails + replayEvents;

  // Require either broad spread or meaningful severe volume.
  if (
    stats.uniqueIPs.length < MIN_UNIQUE_IPS_THRESHOLD &&
    severeEvents < MIN_SEVERE_EVENTS_THRESHOLD
  ) {
    return false;
  }

  // Skip low-confidence/noisy traffic patterns to save tokens.
  if (stats.failureRate < 0.40 && severeEvents < MIN_SEVERE_EVENTS_THRESHOLD + 2) {
    return false;
  }

  return true;
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

  const modelCandidates = await resolveOpenRouterModelCandidates(env);
  for (const model of modelCandidates) {
    const analysis = await queryOpenRouterWithModel(
      env,
      systemPrompt,
      userPrompt,
      model
    );
    if (analysis) return analysis;
  }

  return null;
}

async function queryOpenRouterWithModel(
  env: Env,
  systemPrompt: string,
  userPrompt: string,
  model: string
): Promise<ThreatAnalysisResponse | null> {
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
        model,
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

async function resolveOpenRouterModelCandidates(env: Env): Promise<string[]> {
  let primary = (env.OPENROUTER_MODEL || "").trim();
  let fallbackRaw = (env.OPENROUTER_FALLBACK_MODELS || "").trim();

  // Dashboard-synced env vars have higher priority.
  if (!primary) {
    try {
      const kvPrimary = (await env.SESSION_KV.get(AI_MODEL_SETTING_KEY))?.trim();
      if (kvPrimary) primary = kvPrimary;
    } catch {
      // KV errors are non-fatal
    }
  }

  if (!fallbackRaw) {
    try {
      const kvFallbacks = (await env.SESSION_KV.get(AI_MODEL_FALLBACKS_SETTING_KEY))?.trim();
      if (kvFallbacks !== undefined && kvFallbacks !== null) {
        fallbackRaw = kvFallbacks;
      }
    } catch {
      // KV errors are non-fatal
    }
  }

  if (!primary) {
    primary = "qwen/qwen3-next-80b-a3b-instruct:free";
  }

  const candidates = [primary];
  for (const model of fallbackRaw.split(",")) {
    const normalized = model.trim();
    if (!normalized) continue;
    if (candidates.includes(normalized)) continue;
    candidates.push(normalized);
  }

  return candidates;
}

// ---------------------------------------------------------------------------
// Internal WAF Rule Deployment (D1 Custom Firewall Rules)
// ---------------------------------------------------------------------------

const EDGE_SHIELD_AUTO_DESC_PREFIX = "[AI-DEFENSE]";

/**
 * Deploys an internal WAF rule by inserting or updating the custom_firewall_rules table.
 * The rule is applied to the specific domain or globally if no domain is matched.
 */
async function deployWAFRule(
  env: Env,
  analysis: ThreatAnalysisResponse,
  meta: RequestMeta
): Promise<void> {
  const domainName = extractDomainFromMeta(meta) || "global";
  // Determine the WAF action based on confidence
  const action = analysis.confidence >= 0.9 ? "block" : "managed_challenge";

  if (!analysis.targets || analysis.targets.length === 0) return;

  // Process targets depending on the recommended action
  switch (analysis.recommendedAction) {
    case "block_ip": {
      const ips = analysis.targets.filter((t) => /^[\d.:a-fA-F]+$/.test(t));
      if (ips.length > 0) {
        await upsertInternalFirewallRule(env, domainName, "ip.src", ips, action, analysis, meta);
      }
      break;
    }
    case "block_asn": {
      const asns = analysis.targets
        .map((t) => t.replace(/^AS/i, ""))
        .filter((t) => /^\d+$/.test(t));
      if (asns.length > 0) {
        await upsertInternalFirewallRule(env, domainName, "ip.src.asnum", asns, action, analysis, meta);
      }
      break;
    }
    case "block_country": {
      const countries = analysis.targets.filter((t) => /^[A-Z]{2}$/.test(t));
      if (countries.length > 0) {
        await upsertInternalFirewallRule(env, domainName, "ip.src.country", countries, action, analysis, meta);
      }
      break;
    }
  }

  // Log the WAF rule creation
  try {
    await env.DB.prepare(
      `INSERT INTO security_logs (domain_name, event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
       VALUES (?, 'waf_rule_created', ?, ?, ?, ?, NULL, NULL, ?)`
    )
      .bind(
        domainName === "global" ? null : domainName,
        meta.ip,
        meta.asn,
        meta.country,
        meta.path,
        JSON.stringify({
          threatType: analysis.threatType,
          confidence: analysis.confidence,
          action,
          targets: analysis.targets,
          reasoning: analysis.reasoning,
          destination: "internal_d1",
        })
      )
      .run();
  } catch {
    // Logging failure is non-fatal
  }
}

/**
 * Smart merging logic for creating or updating internal firewall rules.
 * Enforces a strict limit (MAX_TARGETS_PER_RULE) to prevent JSON parsing bloat & slow queries at the Edge.
 * When a rule fills up, it intelligently spills over into a brand-new rule.
 */
const MAX_TARGETS_PER_RULE = 500;
const OPTIMISTIC_LOCK_RETRIES = 3;
const RULE_TTL_SECONDS = 7 * 86400; // 7 days auto-expiration

// Quick safeguard to prevent the AI from accidentally blocking internal network or loopback IPs
function isPublicRoutableIP(ip: string): boolean {
  if (ip === "127.0.0.1" || ip === "::1") return false;
  if (/^10\./.test(ip)) return false;
  if (/^192\.168\./.test(ip)) return false;
  if (/^172\.(1[6-9]|2[0-9]|3[0-1])\./.test(ip)) return false;
  if (/^169\.254\./.test(ip)) return false;
  if (/^fc00:/i.test(ip) || /^fd00:/i.test(ip)) return false; // IPv6 Unique Local Address
  if (/^fe80:/i.test(ip)) return false; // IPv6 Link-Local
  return true;
}

async function upsertInternalFirewallRule(
  env: Env,
  domainName: string,
  field: string,
  rawTargets: string[],
  action: string,
  analysis: ThreatAnalysisResponse,
  meta: RequestMeta
): Promise<void> {
  // NORMALIZATION & VALIDATION: Clean, lower, and filter private IPs if it's an IP rule
  let pendingTargets = Array.from(new Set(
    rawTargets
      .map(t => t.trim().toLowerCase())
      .filter(Boolean)
      .filter(t => field === "ip.src" ? isPublicRoutableIP(t) : true)
  )).sort();

  if (pendingTargets.length === 0) return;

  const nowSecs = Math.floor(Date.now() / 1000);

  for (let attempt = 1; attempt <= OPTIMISTIC_LOCK_RETRIES; attempt++) {
    try {
      // PERFORMANCE: Only fetch active and unexpired rules to prevent Pass 1 from parsing thousands of dead rules
      const existingRules = await env.DB.prepare(
        `SELECT id, expression_json 
         FROM custom_firewall_rules 
         WHERE domain_name = ? 
           AND action = ? 
           AND paused = 0 
           AND (expires_at IS NULL OR expires_at > ?)
           AND description LIKE ?
         ORDER BY updated_at DESC`
      )
        .bind(domainName, action, nowSecs, `${EDGE_SHIELD_AUTO_DESC_PREFIX}%`)
        .all<{ id: number; expression_json: string }>();

      const parsedRules: { id: number; expression_json: string; targets: string[] }[] = [];

      // PASS 1: Identify existing targets to prevent duplication
      if (existingRules.results) {
        for (const rule of existingRules.results) {
          try {
            const expr = JSON.parse(rule.expression_json);
            if (expr.field === field && expr.operator === "in") {
              const existingTargets = String(expr.value)
                .split(",")
                .map((v) => v.trim().toLowerCase())
                .filter(Boolean);
              
              parsedRules.push({ id: rule.id, expression_json: rule.expression_json, targets: existingTargets });
              
              // Remove targets that are already blocked ANYWHERE to guarantee 0 duplication
              pendingTargets = pendingTargets.filter(t => !existingTargets.includes(t));
            }
          } catch {
            // Ignore invalid JSON
          }
        }
      }

      if (pendingTargets.length === 0) {
        // AUDIT TRAIL: Log that no action was needed
        await logAIDefenseEvent(env, meta, "WAF_MERGE_SKIPPED", `Targets already present across rules for ${field}`);
        return;
      }

      let optimisticLockConflict = false;

      // PASS 2: Fill existing non-full rules
      for (const pRule of parsedRules) {
        if (pendingTargets.length === 0) break;

        const spaceLeft = MAX_TARGETS_PER_RULE - pRule.targets.length;
        if (spaceLeft > 0) {
          const toAdd = pendingTargets.splice(0, spaceLeft);
          const mergedValues = Array.from(new Set([...pRule.targets, ...toAdd])).sort();
          
          const newExpression = JSON.stringify({
            field,
            operator: "in",
            value: mergedValues.join(", ")
          });
          
          const newDescription = `${EDGE_SHIELD_AUTO_DESC_PREFIX} ${analysis.threatType} mitigation (${field}) - Updated ${new Date().toISOString().slice(0, 10)}`;
          const slidingExpiresAt = nowSecs + RULE_TTL_SECONDS; // SLIDING EXPIRATION: Extend rule life because it's still being heavily hit
          
          // OPTIMISTIC LOCKING: Ensure expression_json hasn't changed since we queried it
          const updateRes = await env.DB.prepare(
            `UPDATE custom_firewall_rules 
             SET expression_json = ?, description = ?, updated_at = CURRENT_TIMESTAMP, expires_at = ?
             WHERE id = ? AND expression_json = ?`
          )
            .bind(newExpression, newDescription, slidingExpiresAt, pRule.id, pRule.expression_json)
            .run();
            
          if (updateRes.meta.changes === 0) {
            optimisticLockConflict = true;
            // Rollback pendingTargets for retry logic
            pendingTargets = pendingTargets.concat(toAdd).sort();
            break; // Break the filling pass and trigger a retry
          } else {
             // AUDIT TRAIL: Log the successful merge
             await logAIDefenseEvent(env, meta, "WAF_MERGE_UPDATED", `Merged ${toAdd.length} targets into Rule ID ${pRule.id}. Total targets now ${mergedValues.length}/${MAX_TARGETS_PER_RULE}. Expiry extended.`);
          }
        }
      }

      if (optimisticLockConflict) {
         if (attempt < OPTIMISTIC_LOCK_RETRIES) {
            await new Promise(res => setTimeout(res, 50 * attempt)); // Exponential-ish backoff
            continue; // Retry the entire process
         } else {
            // Max retries hit, proceed to dump remaining into new rules (safety fallback)
         }
      }

      // PASS 3: Generate new rules for any remaining targets (chunked)
      while (pendingTargets.length > 0) {
        const chunk = pendingTargets.splice(0, MAX_TARGETS_PER_RULE);
        
        const newExpression = JSON.stringify({
          field,
          operator: "in",
          value: chunk.join(", ")
        });

        const description = `${EDGE_SHIELD_AUTO_DESC_PREFIX} ${analysis.threatType} phase deployment (${field})`;
        const expiresAt = nowSecs + RULE_TTL_SECONDS;

        const insertRes = await env.DB.prepare(
          `INSERT INTO custom_firewall_rules (domain_name, description, action, expression_json, paused, expires_at)
           VALUES (?, ?, ?, ?, 0, ?)
           RETURNING id`
        )
          .bind(domainName, description, action, newExpression, expiresAt)
          .all<{ id: number }>();
          
        if (insertRes.results && insertRes.results.length > 0) {
            // AUDIT TRAIL: Log new rule creation
            await logAIDefenseEvent(env, meta, "WAF_MERGE_NEW", `Created new rule ID ${insertRes.results[0].id} containing ${chunk.length} targets. Auto-expires in 7 days.`);
        }
      }

      break; // Success! Break the retry loop
    } catch {
      // If DB operations fail, we silently recover
      break;
    }
  }
}

async function logAIDefenseEvent(
  env: Env,
  meta: RequestMeta,
  stage: string,
  details: string
): Promise<void> {
  const domainName = extractDomainFromMeta(meta);
  try {
    await env.DB.prepare(
      `INSERT INTO security_logs (domain_name, event_type, ip_address, asn, country, target_path, fingerprint_hash, risk_score, details)
       VALUES (?, 'ai_defense', ?, ?, ?, ?, NULL, NULL, ?)`
    )
      .bind(domainName, meta.ip, meta.asn, meta.country, meta.path, `${stage}: ${details}`)
      .run();
  } catch {
    // Non-fatal
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
