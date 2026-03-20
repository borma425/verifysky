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
} from "./types";
import { RiskLevel } from "./types";

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

type PathProbeRule = {
  pattern: RegExp;
  score: number;
  reason: string;
  strong?: boolean;
};

const SENSITIVE_PATH_RULES: PathProbeRule[] = [
  {
    pattern: /^\/xmlrpc\.php$/i,
    score: 28,
    reason: "XML-RPC abuse pattern probe",
    strong: true,
  },
  {
    pattern: /^\/wp-login\.php$/i,
    score: 20,
    reason: "WordPress login brute-force target",
    strong: true,
  },
  {
    pattern: /^\/(?:wp-config\.php(?:\..*)?|\.env(?:[._-].*)?)$/i,
    score: 45,
    reason: "Sensitive config file probe",
    strong: true,
  },
  {
    pattern: /\/vendor\/phpunit\/phpunit\/src\/util\/php\/eval-stdin\.php$/i,
    score: 45,
    reason: "Known PHPUnit RCE probe",
    strong: true,
  },
  {
    pattern: /(?:^|\/)\.well-known\/.*\.(?:php|phtml?)$/i,
    score: 32,
    reason: "Hidden webshell drop-path probe",
    strong: true,
  },
  {
    pattern: /(?:^|\/)(?:phpinfo|info|test|admin|upload|shell|cmd|ahax|bless|bolt|txets|ioxi-o|class-t\.api)\.php$/i,
    score: 26,
    reason: "Generic webshell/scanner PHP probe",
    strong: true,
  },
  {
    pattern: /(?:^|\/)(?:feed|rss)(?:\/|\.|$)/i,
    score: 8,
    reason: "Feed/rss scraping probe",
  },
  {
    pattern: /(?:\.\.|%2e%2e|%252e%252e)/i,
    score: 30,
    reason: "Path traversal signature detected",
    strong: true,
  },
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
  fingerprintHash?: string | null
): Promise<RiskAssessment> {
  let score = 0;
  const factors: string[] = [];
  let strongBotSignal = false;

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

  // --- Factor 6: Request Path Attack Signatures ---
  // Detect common exploit/scanner probes (WordPress, config leaks, webshell paths).
  const normalizedPath = (meta.path || "").toLowerCase();
  for (const rule of SENSITIVE_PATH_RULES) {
    if (!rule.pattern.test(normalizedPath)) continue;
    score += rule.score;
    factors.push(rule.reason);
    if (rule.strong) strongBotSignal = true;
  }

  // Escalate suspicious PHP probes except for explicit allow-listed endpoints.
  if (normalizedPath.endsWith(".php")) {
    const allowedPhpPaths = new Set([
      "/index.php",
      "/wp-cron.php",
      "/wp-login.php",
      "/wp-admin/admin-ajax.php",
    ]);
    const isLikelyBenignPhp =
      allowedPhpPaths.has(normalizedPath) ||
      normalizedPath.startsWith("/wp-json/") ||
      normalizedPath.startsWith("/wp-admin/") ||
      normalizedPath.startsWith("/wp-content/plugins/litespeed-cache/") ||
      normalizedPath.startsWith("/wp-content/plugins/elementor/") ||
      normalizedPath.startsWith("/wp-content/plugins/seo-by-rank-math/") ||
      normalizedPath.startsWith("/wp-content/plugins/wordpress-seo/");

    if (!isLikelyBenignPhp) {
      score += 14;
      factors.push(`Unexpected PHP endpoint probe: ${meta.path}`);
      strongBotSignal = true;
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

// ---------------------------------------------------------------------------
// Public: IP Rate Counter (Increment)
// ---------------------------------------------------------------------------

/**
 * Increments the request counter for an IP address in KV.
 * The counter auto-expires after 60 seconds (sliding window).
 * Used by the risk engine to detect rapid-fire request patterns.
 */
export async function incrementIPRate(
  ip: string,
  kv: KVNamespace
): Promise<void> {
  const key = `rate:${ip}`;
  const current = await kv.get(key);
  const count = current ? parseInt(current, 10) + 1 : 1;
  // TTL of 60 seconds creates a rolling 1-minute window
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
  const key = `rate:asn:${asn}`;
  const current = await kv.get(key);
  const count = current ? parseInt(current, 10) + 1 : 1;
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
  const v4 = extractIPv4Subnets(ip);
  if (!v4) return;

  const key24 = `rate:subnet4:${v4.subnet24}`;
  const key16 = `rate:subnet4:${v4.subnet16}`;

  const current24 = await kv.get(key24);
  const count24 = current24 ? parseInt(current24, 10) + 1 : 1;
  await kv.put(key24, String(count24), { expirationTtl: 60 });

  const current16 = await kv.get(key16);
  const count16 = current16 ? parseInt(current16, 10) + 1 : 1;
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
  const bucket = normalizePathForRate(path);
  const globalKey = `rate:path:${bucket}`;
  const globalCurrent = await kv.get(globalKey);
  const globalCount = globalCurrent ? parseInt(globalCurrent, 10) + 1 : 1;
  await kv.put(globalKey, String(globalCount), { expirationTtl: 60 });

  if (!asn) return;
  const asnKey = `rate:asnpath:${asn}:${bucket}`;
  const asnCurrent = await kv.get(asnKey);
  const asnCount = asnCurrent ? parseInt(asnCurrent, 10) + 1 : 1;
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
