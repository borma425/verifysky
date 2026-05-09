// ============================================================================
// Ultimate Edge Shield — Behavioral Risk Scoring Engine
// Evaluates incoming requests and assigns a 0-100 risk score.
//
// Scoring factors:
//   - Cloudflare Bot Management score (if available)
//   - User-Agent analysis (missing, known bots, anomalies)
//   - ASN reputation (known datacenter/hosting ASNs)
//   - TLS fingerprint (outdated/missing TLS)
//   - Fingerprint history from D1 (prior failures, risk score)
//   - Request rate signals (derived from KV counters)
//   - Geographic anomalies
// ============================================================================

import type {
  Env,
  RequestMeta,
  RiskAssessment,
  FingerprintRecord,
  DomainThresholds,
} from "./types";
import { RiskLevel } from "./types";
import { getDailyHoneypotPaths, isAdTraffic } from "./utils";

// ---------------------------------------------------------------------------
// Known datacenter/hosting ASN prefixes (commonly used by bots)
// These ASNs frequently appear in automated traffic.
// ---------------------------------------------------------------------------
const DATACENTER_ASNS: Set<string> = new Set([
  "14061",  // DigitalOcean
  "16276",  // OVH
  "24940",  // Hetzner
  "63949",  // Linode/Akamai
  "20473",  // Vultr/Choopa
  "14618",  // Amazon AWS
  "15169",  // Google Cloud
  "8075",   // Microsoft Azure
  "396982", // Google (GCP)
  "13335",  // Cloudflare (unusual for legitimate end-users)
  "55286",  // Tencent Cloud
  "45102",  // Alibaba Cloud
]);

// ---------------------------------------------------------------------------
// Suspicious User-Agent patterns
// ---------------------------------------------------------------------------
const BOT_UA_PATTERNS: RegExp[] = [
  /bot|crawl|spider|scrape|slurp/i,
  /python-requests|python-urllib|aiohttp/i,
  /curl|wget|httpie|fetch/i,
  /phantom|headless|puppeteer|playwright|selenium/i,
  /java\/|apache-httpclient|okhttp/i,
  /go-http-client|node-fetch|axios/i,
  /libwww|lwp-|mechanize/i,
];



// User-Agents that are too short or clearly malformed
const MIN_UA_LENGTH = 20;
const SUBNET24_BURST_SOFT = 25;
const SUBNET24_BURST_HIGH = 45;
const SUBNET24_BURST_EXTREME = 80;
const SUBNET16_BURST_SOFT = 150;
const SUBNET16_BURST_HIGH = 250;
const PATH_BURST_SOFT = 120;
const PATH_BURST_HIGH = 220;
const ASN_PATH_BURST_SOFT = 25;
const ASN_PATH_BURST_HIGH = 50;
const IP_ATTACK_DAY_PREFIX = "attack:ip:day:";
const IP_ATTACK_MONTH_PREFIX = "attack:ip:month:";

// ---------------------------------------------------------------------------
// Public: Risk Scoring Engine
// ---------------------------------------------------------------------------

/**
 * Evaluates a request and produces a comprehensive risk assessment.
 * The score ranges from 0 (completely trusted) to 100 (confirmed malicious).
 *
 * Scoring is additive: each factor contributes points to the total.
 * The final score is clamped to [0, 100].
 *
 * Three-tier classification:
 *   - NORMAL     (0–30):  Transparent pass
 *   - SUSPICIOUS (31–70): Slider CAPTCHA + Turnstile challenge
 *   - MALICIOUS  (71+):   Hard block at the edge
 */
export async function evaluateRisk(
  meta: RequestMeta,
  env: Env,
  fingerprintHash?: string | null,
  options: { readVolatileSignals?: boolean; writeSignals?: boolean } = {}
): Promise<RiskAssessment> {
  let score = 0;
  const factors: string[] = [];
  let strongBotSignal = false;
  const readVolatileSignals = options.readVolatileSignals !== false;
  const writeSignals = options.writeSignals !== false;

  // --- Factor 1: Cloudflare Bot Management Score ---
  // CF provides a 1-99 score where 1 = definitely bot, 99 = definitely human.
  // This is the most reliable signal but only available on paid plans.
  if (meta.botManagementScore !== null) {
    if (meta.botManagementScore <= 5) {
      score += 40;
      factors.push(`CF Bot Score critically low: ${meta.botManagementScore}`);
      strongBotSignal = true;
    } else if (meta.botManagementScore <= 20) {
      score += 25;
      factors.push(`CF Bot Score suspicious: ${meta.botManagementScore}`);
      strongBotSignal = true;
    } else if (meta.botManagementScore <= 40) {
      score += 10;
      factors.push(`CF Bot Score below average: ${meta.botManagementScore}`);
    }
    // Score > 40: no penalty (likely human)
  } else {
    // No Bot Management data — slight uncertainty penalty
    score += 5;
    factors.push("No CF Bot Management data available");
  }

  // --- Factor 2: User-Agent Analysis ---
  if (!meta.userAgent || meta.userAgent === "unknown") {
    score += 20;
    factors.push("Missing User-Agent header");
    strongBotSignal = true;
  } else if (meta.userAgent.length < MIN_UA_LENGTH) {
    score += 15;
    factors.push(`Suspiciously short User-Agent (${meta.userAgent.length} chars)`);
    strongBotSignal = true;
  } else {
    for (const pattern of BOT_UA_PATTERNS) {
      if (pattern.test(meta.userAgent)) {
        score += 20;
        factors.push(`Bot-like User-Agent pattern: ${pattern.source}`);
        strongBotSignal = true;
        break; // Only penalize once for UA
      }
    }
  }

  // --- Factor 3: Datacenter ASN Detection ---
  if (meta.asn && DATACENTER_ASNS.has(meta.asn)) {
    score += 15;
    factors.push(`Datacenter/hosting ASN detected: AS${meta.asn}`);
    strongBotSignal = true;
  }

  // --- Factor 4: TLS Analysis ---
  if (meta.tlsVersion) {
    if (meta.tlsVersion === "TLSv1" || meta.tlsVersion === "TLSv1.1") {
      score += 10;
      factors.push(`Outdated TLS version: ${meta.tlsVersion}`);
    }
  } else if (meta.httpProtocol) {
    // No TLS at all (plain HTTP) — highly suspicious in production
    score += 10;
    factors.push("No TLS detected (plain HTTP connection)");
  }

  // --- Factor 5: Missing Country/ASN Data ---
  // Legitimate Cloudflare requests almost always have geo data.
  // Missing data suggests proxy/tunnel/synthetic requests.
  if (!meta.country) {
    score += 5;
    factors.push("Missing country information");
  }
  if (!meta.asn) {
    score += 5;
    factors.push("Missing ASN information");
  }



  // --- Factor 6: Header Anomaly & Protocol Profiling ---
  if (!meta.verifiedBot && meta.userAgent) {
    const isModernBrowserClaim = /Chrome|Firefox|Safari|Edge|Opera/i.test(meta.userAgent) && !/bot|crawl|spider/i.test(meta.userAgent);
    
    if (isModernBrowserClaim) {
      if (!meta.acceptLanguage) {
         score += 25;
         factors.push("Missing Accept-Language header in claimed modern browser");
         strongBotSignal = true;
      }
      if (meta.httpProtocol && (meta.httpProtocol === "HTTP/1.1" || meta.httpProtocol === "HTTP/1.0")) {
         score += 15;
         factors.push(`Suspicious protocol (${meta.httpProtocol}) for claimed modern browser`);
      }
      if (meta.method === "GET" && !meta.secFetchSite && !meta.secFetchMode && /Chrome|Edge/i.test(meta.userAgent)) {
         score += 10;
         factors.push("Missing Fetch Metadata headers in claimed Chromium browser");
      }
    }
  }

  // --- Factor 6.5: Ad Traffic Strictness / Click Spoofing ---
  if (isAdTraffic(meta.url)) {
    // Legitimate ad traffic from Google/Meta/TikTok typically passes a Referer.
    // However, some privacy browsers (or iOS in-app browsers) strip the Referer.
    // We combine signals: No Referer + No Accept-Language = Strong Spoofing Signal.
    if (!meta.referer) {
      if (!meta.acceptLanguage || strongBotSignal) {
        score += 30;
        factors.push("Spoofed Ad Click (Contains ad trackers but lacks organic Referer and Language headers)");
        strongBotSignal = true;
      } else {
        score += 10;
        factors.push("Suspicious Ad Click (Contains ad trackers but lacks organic Referer)");
      }
    }
  }

  // --- Factor 7: Historical Fingerprint Data (D1 lookup) ---
  if (fingerprintHash) {
    try {
      const fpRecord = await env.DB.prepare(
        "SELECT risk_score, fail_count, challenge_count, asn, country FROM fingerprints WHERE hash = ?"
      )
        .bind(fingerprintHash)
        .first<FingerprintRecord>();

      if (fpRecord) {
        // High historical risk score
        if (fpRecord.risk_score >= 80) {
          score += 25;
          factors.push(`Known high-risk fingerprint (historical score: ${fpRecord.risk_score})`);
        } else if (fpRecord.risk_score >= 60) {
          score += 15;
          factors.push(`Moderate-risk fingerprint (historical score: ${fpRecord.risk_score})`);
        }

        // Excessive failures indicate bot-like behavior
        if (fpRecord.fail_count >= 5) {
          score += 20;
          factors.push(`High failure count: ${fpRecord.fail_count} failed challenges`);
        } else if (fpRecord.fail_count >= 3) {
          score += 10;
          factors.push(`Elevated failure count: ${fpRecord.fail_count} failed challenges`);
        }

        // Proxy-hopping detection: same fingerprint, different ASN/country
        if (meta.asn && fpRecord.asn && meta.asn !== fpRecord.asn) {
          const asnSimilarity = getASNSimilarity(meta.asn, fpRecord.asn);
          const hasPriorRisk = fpRecord.fail_count >= 3 || fpRecord.risk_score >= 60;

          if (asnSimilarity === "near") {
            score += 4;
            factors.push(
              `ASN shifted within nearby range: AS${meta.asn} vs historical AS${fpRecord.asn}`
            );
          } else if (strongBotSignal || hasPriorRisk) {
            score += 15;
            factors.push(`ASN mismatch (high confidence): current AS${meta.asn} vs historical AS${fpRecord.asn}`);
          } else {
            score += 6;
            factors.push(`ASN mismatch (low confidence): current AS${meta.asn} vs historical AS${fpRecord.asn}`);
          }
        }
        if (meta.country && fpRecord.country && meta.country !== fpRecord.country) {
          const hasPriorRisk = fpRecord.fail_count >= 3 || fpRecord.risk_score >= 60;
          if (strongBotSignal || hasPriorRisk) {
            score += 10;
            factors.push(`Country mismatch (high confidence): ${meta.country} vs historical ${fpRecord.country}`);
          } else {
            score += 3;
            factors.push(`Country changed since last fingerprint activity: ${meta.country} vs ${fpRecord.country}`);
          }
        }
      }
    } catch {
      // D1 lookup failure should not crash the request — proceed with caution
      score += 5;
      factors.push("Fingerprint D1 lookup failed (proceeding with caution)");
    }
  }

  if (readVolatileSignals) {
    // --- Factor 8: IP-based Rate Signal (KV fast lookup) ---
    try {
      const ipRequestKey = `rate:${meta.ip}`;
      const rateData = await env.SESSION_KV.get(ipRequestKey);
      if (rateData) {
        const count = parseInt(rateData, 10);
        if (count > 50) {
          score += 20;
          factors.push(`High request rate from IP: ${count} recent requests`);
        } else if (count > 20) {
          score += 10;
          factors.push(`Elevated request rate from IP: ${count} recent requests`);
        }
      }
    } catch {
      // KV failure is non-fatal
    }

    // --- Factor 9: ASN-based Burst Signal (botnet/cluster behavior) ---
    // Helps detect distributed traffic from rotating IPs inside one ASN.
    if (meta.asn) {
      try {
        const asnRequestKey = `rate:asn:${meta.asn}`;
        const asnRateData = await env.SESSION_KV.get(asnRequestKey);
        if (asnRateData) {
          const asnCount = parseInt(asnRateData, 10);
          if (asnCount > 200) {
            score += 20;
            factors.push(`High ASN traffic burst: AS${meta.asn} (${asnCount}/min)`);
          } else if (asnCount > 100) {
            score += 10;
            factors.push(`Elevated ASN traffic: AS${meta.asn} (${asnCount}/min)`);
          }
        }
      } catch {
        // KV failure is non-fatal
      }
    }

    // --- Factor 10: Subnet Burst Detection (/24 and /16) ---
    // Detects "rotating IP swarm" attacks where each IP sends a single request.
    // We keep this conservative to avoid harming legitimate residential traffic.
    try {
      const v4 = extractIPv4Subnets(meta.ip);
      if (v4) {
        const subnet24Key = `rate:subnet4:${v4.subnet24}`;
        const subnet16Key = `rate:subnet4:${v4.subnet16}`;
        const subnet24Count = parseInt((await env.SESSION_KV.get(subnet24Key)) || "0", 10) || 0;
        const subnet16Count = parseInt((await env.SESSION_KV.get(subnet16Key)) || "0", 10) || 0;

        if (strongBotSignal) {
          if (subnet24Count > SUBNET24_BURST_HIGH) {
            score += 20;
            factors.push(`High /24 burst detected (${v4.subnet24}.x: ${subnet24Count}/min)`);
          } else if (subnet24Count > SUBNET24_BURST_SOFT) {
            score += 10;
            factors.push(`Elevated /24 burst (${v4.subnet24}.x: ${subnet24Count}/min)`);
          }

          if (subnet16Count > SUBNET16_BURST_HIGH) {
            score += 12;
            factors.push(`High /16 burst detected (${v4.subnet16}.x.x: ${subnet16Count}/min)`);
          } else if (subnet16Count > SUBNET16_BURST_SOFT) {
            score += 6;
            factors.push(`Elevated /16 burst (${v4.subnet16}.x.x: ${subnet16Count}/min)`);
          }
        } else if (subnet24Count > SUBNET24_BURST_EXTREME) {
          // Even without strong bot signals, very extreme subnet storms should
          // at least be challenged.
          score += 10;
          factors.push(`Extreme /24 burst anomaly (${v4.subnet24}.x: ${subnet24Count}/min)`);
        }
      }
    } catch {
      // KV failure is non-fatal
    }

    // --- Factor 11: Path Concentration Signal (bot farm pressure) ---
    // Detects concentrated pressure on a specific endpoint globally and per-ASN.
    try {
      const pathBucket = normalizePathForRate(meta.path);
      const pathKey = `rate:path:${pathBucket}`;
      const pathCount = parseInt((await env.SESSION_KV.get(pathKey)) || "0", 10) || 0;

      if (pathCount > PATH_BURST_HIGH) {
        score += 12;
        factors.push(`High path pressure (${pathBucket}: ${pathCount}/min)`);
      } else if (pathCount > PATH_BURST_SOFT) {
        score += 6;
        factors.push(`Elevated path pressure (${pathBucket}: ${pathCount}/min)`);
      }

      if (meta.asn) {
        const asnPathKey = `rate:asnpath:${meta.asn}:${pathBucket}`;
        const asnPathCount = parseInt((await env.SESSION_KV.get(asnPathKey)) || "0", 10) || 0;
        if (asnPathCount > ASN_PATH_BURST_HIGH) {
          score += 18;
          factors.push(
            `High ASN+path concentration (AS${meta.asn} on ${pathBucket}: ${asnPathCount}/min)`
          );
          strongBotSignal = true;
        } else if (asnPathCount > ASN_PATH_BURST_SOFT) {
          score += 10;
          factors.push(
            `Elevated ASN+path pressure (AS${meta.asn} on ${pathBucket}: ${asnPathCount}/min)`
          );
        }
      }
    } catch {
      // KV failure is non-fatal
    }

    // --- Factor 12: Historical IP Attack Reputation (today / yesterday / month) ---
    // Lightweight KV-only lookups (UTC buckets), no per-request D1 query.
    // Applied conservatively to avoid hurting normal users.
    try {
      const todayKey = `${IP_ATTACK_DAY_PREFIX}${utcDayKey()}:${meta.ip}`;
      const todayCount = parseInt((await env.SESSION_KV.get(todayKey)) || "0", 10) || 0;

      if (todayCount > 0) {
        const yesterdayKey = `${IP_ATTACK_DAY_PREFIX}${utcDayKey(-1)}:${meta.ip}`;
        const monthKey = `${IP_ATTACK_MONTH_PREFIX}${utcMonthKey()}:${meta.ip}`;
        const yesterdayCount = parseInt((await env.SESSION_KV.get(yesterdayKey)) || "0", 10) || 0;
        const monthCount = parseInt((await env.SESSION_KV.get(monthKey)) || "0", 10) || 0;

        if (todayCount >= 20) {
          score += 14;
          factors.push(`IP historical attacks today: ${todayCount}`);
        } else if (todayCount >= 8) {
          score += 8;
          factors.push(`IP repeated attacks today: ${todayCount}`);
        } else if (todayCount >= 3) {
          score += 4;
          factors.push(`IP attack history detected today: ${todayCount}`);
        }

        if (yesterdayCount >= 10) {
          score += 6;
          factors.push(`IP attacks yesterday: ${yesterdayCount}`);
        } else if (yesterdayCount >= 4) {
          score += 3;
          factors.push(`IP had attack carry-over from yesterday: ${yesterdayCount}`);
        }

        if (monthCount >= 60) {
          score += 8;
          factors.push(`IP monthly attack history is high: ${monthCount}`);
        } else if (monthCount >= 25) {
          score += 4;
          factors.push(`IP monthly attack history is elevated: ${monthCount}`);
        }
      }
    } catch {
      // KV failure is non-fatal
    }
  }

  // --- Factor 13: Dynamic Honeypot Decoy Analysis ---
  const dailyHoneypots = await getDailyHoneypotPaths(env);
  if (dailyHoneypots.includes(meta.path)) {
    if (!meta.isPrefetch) {
      try {
        const decoyKey = `honeypot_hit:${meta.ip}`;
        const currentStr = readVolatileSignals ? await env.SESSION_KV.get(decoyKey) : null;
        const currentCount = currentStr ? parseInt(currentStr, 10) : 0;
        const newCount = currentCount + 1;
        if (writeSignals) {
          await env.SESSION_KV.put(decoyKey, String(newCount), { expirationTtl: 86400 });
        }

        if (newCount >= 2) {
          score += 45;
          factors.push(`Repeated Honeypot hits (${newCount}) — strong scraper signal`);
          strongBotSignal = true;
        } else {
          score += 25;
          factors.push(`Initial Honeypot hit — suspicious crawler activity`);
        }
      } catch {
        score += 25;
        factors.push(`Honeypot hit (KV tracking failed)`);
      }
    }
  }

  // --- Factor 14: ASN Network Reputation ---
  if (readVolatileSignals && meta.asn) {
    try {
      const asnAttackKey = `attack:asn:day:${utcDayKey()}:${meta.asn}`;
      const asnAttackCount = parseInt((await env.SESSION_KV.get(asnAttackKey)) || "0", 10) || 0;
      if (asnAttackCount > 50) {
        score += 10;
        factors.push(`ASN exhibits sustained malicious behavior today (${asnAttackCount} blocks)`);
      } else if (asnAttackCount > 20) {
        score += 5;
        factors.push(`ASN exhibits elevated malicious behavior today (${asnAttackCount} blocks)`);
      }
    } catch {}
  }

  // Clamp score to [0, 100]
  score = Math.max(0, Math.min(100, score));

  // Classify risk level
  let level: RiskLevel;
  if (score > 70) {
    level = RiskLevel.MALICIOUS;
  } else if (score > 30) {
    level = RiskLevel.SUSPICIOUS;
  } else {
    level = RiskLevel.NORMAL;
  }

  return {
    score,
    level,
    factors,
    ip: meta.ip,
    asn: meta.asn,
    country: meta.country,
    userAgent: meta.userAgent,
    botScore: meta.botManagementScore,
  };
}

function utcDayKey(dayOffset = 0): string {
  const now = new Date();
  if (dayOffset !== 0) {
    now.setUTCDate(now.getUTCDate() + dayOffset);
  }
  return now.toISOString().slice(0, 10);
}

function utcMonthKey(): string {
  return new Date().toISOString().slice(0, 7);
}

// ---------------------------------------------------------------------------
// Public: IP Rate Counter (Increment)
// ---------------------------------------------------------------------------

// Probabilistic write sampling rate: 0.2 = 20% of requests write,
// each write adds 5 (1/0.2) to keep counters accurate on average.
// This reduces KV writes by ~80% with negligible accuracy loss.
const RATE_WRITE_SAMPLE = 0.2;
const RATE_WRITE_BOOST = Math.round(1 / RATE_WRITE_SAMPLE); // = 5

/**
 * Increments the request counter for an IP address in KV.
 * The counter auto-expires after 60 seconds (sliding window).
 * Used by the risk engine to detect rapid-fire request patterns.
 */
export async function incrementIPRate(
  ip: string,
  kv: KVNamespace
): Promise<void> {
  if (Math.random() >= RATE_WRITE_SAMPLE) return; // Probabilistic skip
  const key = `rate:${ip}`;
  const current = await kv.get(key);
  const count = current ? parseInt(current, 10) + RATE_WRITE_BOOST : RATE_WRITE_BOOST;
  await kv.put(key, String(count), { expirationTtl: 60 });
}

/**
 * Returns current 1-minute request counter for an IP from KV.
 */
export async function getIPRateCount(
  ip: string,
  kv: KVNamespace
): Promise<number> {
  const key = `rate:${ip}`;
  const current = await kv.get(key);
  if (!current) return 0;
  const parsed = parseInt(current, 10);
  return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * Increments a coarse ASN request counter (1-minute window).
 * Useful for spotting distributed botnet bursts from the same provider ASN.
 */
export async function incrementASNRate(
  asn: string,
  kv: KVNamespace
): Promise<void> {
  if (Math.random() >= RATE_WRITE_SAMPLE) return;
  const key = `rate:asn:${asn}`;
  const current = await kv.get(key);
  const count = current ? parseInt(current, 10) + RATE_WRITE_BOOST : RATE_WRITE_BOOST;
  await kv.put(key, String(count), { expirationTtl: 60 });
}

/**
 * Increments IPv4 subnet counters (/24 and /16) to detect rotating-IP swarms.
 * A conservative 60s window is used to align with other rate signals.
 */
export async function incrementSubnetRate(
  ip: string,
  kv: KVNamespace
): Promise<void> {
  if (Math.random() >= RATE_WRITE_SAMPLE) return;
  const v4 = extractIPv4Subnets(ip);
  if (!v4) return;

  const key24 = `rate:subnet4:${v4.subnet24}`;
  const key16 = `rate:subnet4:${v4.subnet16}`;

  const current24 = await kv.get(key24);
  const count24 = current24 ? parseInt(current24, 10) + RATE_WRITE_BOOST : RATE_WRITE_BOOST;
  await kv.put(key24, String(count24), { expirationTtl: 60 });

  const current16 = await kv.get(key16);
  const count16 = current16 ? parseInt(current16, 10) + RATE_WRITE_BOOST : RATE_WRITE_BOOST;
  await kv.put(key16, String(count16), { expirationTtl: 60 });
}

/**
 * Increments path-centric counters for 60s windows.
 * Helps detect bot farms concentrating traffic on specific URLs.
 */
export async function incrementPathRate(
  path: string,
  asn: string | null,
  kv: KVNamespace
): Promise<void> {
  if (Math.random() >= RATE_WRITE_SAMPLE) return;
  const bucket = normalizePathForRate(path);
  const globalKey = `rate:path:${bucket}`;
  const globalCurrent = await kv.get(globalKey);
  const globalCount = globalCurrent ? parseInt(globalCurrent, 10) + RATE_WRITE_BOOST : RATE_WRITE_BOOST;
  await kv.put(globalKey, String(globalCount), { expirationTtl: 60 });

  if (!asn) return;
  const asnKey = `rate:asnpath:${asn}:${bucket}`;
  const asnCurrent = await kv.get(asnKey);
  const asnCount = asnCurrent ? parseInt(asnCurrent, 10) + RATE_WRITE_BOOST : RATE_WRITE_BOOST;
  await kv.put(asnKey, String(asnCount), { expirationTtl: 60 });
}

function extractIPv4Subnets(
  ip: string
): { subnet24: string; subnet16: string } | null {
  const m = ip.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
  if (!m) return null;

  const octets = [m[1], m[2], m[3], m[4]].map((x) => parseInt(x, 10));
  if (octets.some((o) => Number.isNaN(o) || o < 0 || o > 255)) return null;

  return {
    subnet24: `${octets[0]}.${octets[1]}.${octets[2]}`,
    subnet16: `${octets[0]}.${octets[1]}`,
  };
}

function normalizePathForRate(path: string): string {
  const raw = (path || "/").toLowerCase();
  let normalized = raw;

  // Collapse dynamic/noisy segments to reduce key cardinality in KV.
  normalized = normalized.replace(/\/\d{3,}(?=\/|$)/g, "/:id");
  normalized = normalized.replace(/\/[0-9a-f]{8,}(?=\/|$)/g, "/:id");
  normalized = normalized.replace(/\/[a-z0-9_-]{24,}(?=\/|$)/g, "/:id");

  // Keep keys bounded and stable.
  if (normalized.length > 140) {
    normalized = normalized.slice(0, 140);
  }

  return normalized || "/";
}

function getASNSimilarity(currentAsn: string, historicalAsn: string): "exact" | "near" | "far" {
  if (currentAsn === historicalAsn) return "exact";

  // ASN numbers are not strictly hierarchical, but a shared leading prefix
  // can be used as a weak neighborhood hint to reduce false positives.
  const cur = currentAsn.trim();
  const hist = historicalAsn.trim();
  if (!/^\d+$/.test(cur) || !/^\d+$/.test(hist)) return "far";

  const minLen = Math.min(cur.length, hist.length);
  if (minLen >= 3 && cur.slice(0, 3) === hist.slice(0, 3)) return "near";
  if (minLen >= 2 && cur.slice(0, 2) === hist.slice(0, 2)) return "near";
  return "far";
}

// ---------------------------------------------------------------------------
// Flood Protection — Smart Dual-Window Visitor Rate Limiting (Hardened)
// ---------------------------------------------------------------------------
// Composite key (IP + SHA-256(UA)) to avoid punishing shared-NAT users.
// Dual windows: burst (15s) + sustained (60s).
// UA entropy detection: IP-only fallback activates when bots rotate UAs.
// ---------------------------------------------------------------------------

const FLOOD_BURST_WINDOW_SECONDS = 15;
const FLOOD_SUSTAINED_WINDOW_SECONDS = 60;
const FLOOD_UA_ENTROPY_THRESHOLD = 5;

export type FloodAction = "pass" | "challenge" | "block";

export interface FloodStatus {
  burst: number;
  sustained: number;
  ipBurst: number;
  ipSustained: number;
  uaEntropy: number;
  action: FloodAction;
  /** Which window/layer triggered the action */
  triggerWindow: "none" | "burst" | "sustained" | "ip_burst" | "ip_sustained";
}

/**
 * SHA-256 based UA fingerprint.
 * Takes first 12 hex characters (6 bytes) — collision resistance: 2^48.
 * Uses Web Crypto API available in Cloudflare Workers.
 */
async function sha256Short(str: string): Promise<string> {
  const data = new TextEncoder().encode(str);
  const hashBuffer = await crypto.subtle.digest("SHA-256", data);
  const hashArray = new Uint8Array(hashBuffer);
  let hex = "";
  for (let i = 0; i < 6; i++) {
    hex += hashArray[i].toString(16).padStart(2, "0");
  }
  return hex;
}

/**
 * Increments all flood counters in one async call (ctx.waitUntil'd).
 *
 * Counters tracked:
 *   - Composite (IP+UA): burst + sustained
 *   - IP-only: burst + sustained (fallback for UA-rotating bots)
 *   - UA diversity: distinct UA count per IP (entropy detection)
 */
export async function incrementFloodCounters(
  ip: string,
  userAgent: string,
  kv: KVNamespace
): Promise<void> {
  const uaHash = await sha256Short(userAgent || "unknown");
  const composite = `${ip}:${uaHash}`;

  // Fire all increments in parallel for max throughput
  const ops: Promise<void>[] = [];

  // 1. Composite counters (IP+UA)
  ops.push(kvIncrement(kv, `flood:b:${composite}`, FLOOD_BURST_WINDOW_SECONDS));
  ops.push(kvIncrement(kv, `flood:s:${composite}`, FLOOD_SUSTAINED_WINDOW_SECONDS));

  // 2. IP-only counters (fallback layer)
  ops.push(kvIncrement(kv, `flood:ib:${ip}`, FLOOD_BURST_WINDOW_SECONDS));
  ops.push(kvIncrement(kv, `flood:is:${ip}`, FLOOD_SUSTAINED_WINDOW_SECONDS));

  // 3. UA diversity tracking per IP
  const uaSeenKey = `flood:uas:${ip}:${uaHash}`;
  ops.push(
    (async () => {
      try {
        const alreadySeen = await kv.get(uaSeenKey);
        if (!alreadySeen) {
          await kv.put(uaSeenKey, "1", {
            expirationTtl: FLOOD_SUSTAINED_WINDOW_SECONDS,
          });
          await kvIncrement(kv, `flood:uac:${ip}`, FLOOD_SUSTAINED_WINDOW_SECONDS);
        }
      } catch {
        // Non-fatal
      }
    })()
  );

  await Promise.allSettled(ops);
}

/**
 * Reads all flood counters in a single parallel batch and returns a decision.
 * Only performs reads — zero writes, zero blocking side-effects.
 */
export async function getFloodStatus(
  ip: string,
  userAgent: string,
  kv: KVNamespace,
  thresholds: DomainThresholds
): Promise<FloodStatus> {
  const uaHash = await sha256Short(userAgent || "unknown");
  const composite = `${ip}:${uaHash}`;

  // Single parallel batch — 5 KV reads simultaneously
  const [burstStr, sustainedStr, ipBurstStr, ipSustainedStr, uaCountStr] =
    await Promise.all([
      kv.get(`flood:b:${composite}`).catch(() => null),
      kv.get(`flood:s:${composite}`).catch(() => null),
      kv.get(`flood:ib:${ip}`).catch(() => null),
      kv.get(`flood:is:${ip}`).catch(() => null),
      kv.get(`flood:uac:${ip}`).catch(() => null),
    ]);

  const burst = safeParseInt(burstStr);
  const sustained = safeParseInt(sustainedStr);
  const ipBurst = safeParseInt(ipBurstStr);
  const ipSustained = safeParseInt(ipSustainedStr);
  const uaEntropy = safeParseInt(uaCountStr);

  const base: Omit<FloodStatus, "action" | "triggerWindow"> = {
    burst,
    sustained,
    ipBurst,
    ipSustained,
    uaEntropy,
  };

  // --- Layer 1: Composite key (IP+UA) — primary protection ---
  if (burst >= thresholds.flood_burst_block) {
    return { ...base, action: "block", triggerWindow: "burst" };
  }
  if (sustained >= thresholds.flood_sustained_block) {
    return { ...base, action: "block", triggerWindow: "sustained" };
  }
  if (burst >= thresholds.flood_burst_challenge) {
    return { ...base, action: "challenge", triggerWindow: "burst" };
  }
  if (sustained >= thresholds.flood_sustained_challenge) {
    return { ...base, action: "challenge", triggerWindow: "sustained" };
  }

  // --- Layer 2: IP-only fallback (activates on UA rotation) ---
  // Only enforced when high UA entropy is detected, to protect NAT users.
  // Calculated dynamically as roughly 2x the normal thresholds.
  const ipBurstBlock = thresholds.flood_burst_block * 2;
  const ipSustBlock = thresholds.flood_sustained_block * 2;
  const ipBurstChallenge = thresholds.flood_burst_challenge * 2;
  // Previously hardcoded as 50, now 2x sustained block or challenge? Let's use roughly 2x challenge, but not higher than block:
  const ipSustChallenge = Math.max(thresholds.flood_sustained_challenge * 2, thresholds.flood_sustained_block);

  if (uaEntropy >= FLOOD_UA_ENTROPY_THRESHOLD) {
    if (ipBurst >= ipBurstBlock) {
      return { ...base, action: "block", triggerWindow: "ip_burst" };
    }
    if (ipSustained >= ipSustBlock) {
      return { ...base, action: "block", triggerWindow: "ip_sustained" };
    }
    if (ipBurst >= ipBurstChallenge) {
      return { ...base, action: "challenge", triggerWindow: "ip_burst" };
    }
    if (ipSustained >= ipSustChallenge) {
      return { ...base, action: "challenge", triggerWindow: "ip_sustained" };
    }
  }

  return { ...base, action: "pass", triggerWindow: "none" };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Atomic-style KV increment with probabilistic sampling. Non-fatal. */
async function kvIncrement(
  kv: KVNamespace,
  key: string,
  ttl: number
): Promise<void> {
  if (Math.random() >= RATE_WRITE_SAMPLE) return; // Probabilistic skip
  try {
    const current = await kv.get(key);
    const count = current ? parseInt(current, 10) + RATE_WRITE_BOOST : RATE_WRITE_BOOST;
    await kv.put(key, String(count), { expirationTtl: ttl });
  } catch {
    // KV failure is non-fatal
  }
}

function safeParseInt(value: string | null): number {
  if (!value) return 0;
  const parsed = parseInt(value, 10);
  return Number.isFinite(parsed) ? parsed : 0;
}
