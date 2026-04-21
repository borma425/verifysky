import type { RequestMeta } from "./types";
import { generateSignature, hashFingerprint, verifySignature } from "./crypto";

export const METER_COOKIE_NAME = "es_meter";
export const METER_TTL_SECONDS = 30 * 60;

interface MeterTokenPayload {
  v: 1;
  d: string;
  fp: string;
  iat: number;
  exp: number;
}

export interface MeteringState {
  shouldMeter: boolean;
  alreadyMetered: boolean;
  cookieHeader: string | null;
}

const ENCODER = new TextEncoder();
const DECODER = new TextDecoder();

function base64UrlEncode(input: string): string {
  const bytes = ENCODER.encode(input);
  const binString = Array.from(bytes, (b) => String.fromCharCode(b)).join("");
  return btoa(binString)
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");
}

function base64UrlDecode(input: string): string {
  let base64 = input.replace(/-/g, "+").replace(/_/g, "/");
  const pad = base64.length % 4;
  if (pad === 2) base64 += "==";
  else if (pad === 3) base64 += "=";
  else if (pad !== 0) throw new Error("Invalid base64url payload");

  const binString = atob(base64);
  const bytes = Uint8Array.from(binString, (c) => c.charCodeAt(0));
  return DECODER.decode(bytes);
}

function normalizeDomain(domain: string): string {
  return domain.trim().toLowerCase();
}

async function meterFingerprint(ip: string, userAgent: string): Promise<string> {
  return hashFingerprint([ip, userAgent || "unknown"]).then((hash) => hash.slice(0, 32));
}

export function isMeterableHtmlRequest(request: Request, meta: RequestMeta): boolean {
  if (request.method !== "GET") return false;
  if (meta.isPrefetch || meta.verifiedBot) return false;
  if (meta.path.startsWith("/es-admin/") || meta.path.startsWith("/es-verify/")) return false;

  const accept = request.headers.get("Accept") || "";
  return accept.toLowerCase().includes("text/html");
}

export async function generateMeterToken(
  domain: string,
  ip: string,
  userAgent: string,
  secret: string,
  now: number = Math.floor(Date.now() / 1000)
): Promise<string> {
  const payload: MeterTokenPayload = {
    v: 1,
    d: normalizeDomain(domain),
    fp: await meterFingerprint(ip, userAgent),
    iat: now,
    exp: now + METER_TTL_SECONDS,
  };
  const payloadB64 = base64UrlEncode(JSON.stringify(payload));
  const signature = await generateSignature(payloadB64, secret);
  return `${payloadB64}.${signature}`;
}

export async function verifyMeterToken(
  token: string | null,
  domain: string,
  ip: string,
  userAgent: string,
  secret: string,
  now: number = Math.floor(Date.now() / 1000)
): Promise<boolean> {
  if (!token) return false;

  try {
    const [payloadB64, signature] = token.split(".");
    if (!payloadB64 || !/^[a-f0-9]{64}$/i.test(signature || "")) return false;

    const validSignature = await verifySignature(payloadB64, signature, secret);
    if (!validSignature) return false;

    const payload = JSON.parse(base64UrlDecode(payloadB64)) as Partial<MeterTokenPayload>;
    if (payload.v !== 1) return false;
    if (payload.d !== normalizeDomain(domain)) return false;
    if (typeof payload.iat !== "number" || typeof payload.exp !== "number") return false;
    if (payload.exp < now) return false;
    if (payload.iat > now + 60) return false;

    const expectedFingerprint = await meterFingerprint(ip, userAgent);
    return payload.fp === expectedFingerprint;
  } catch {
    return false;
  }
}

export function buildMeterCookie(token: string): string {
  return [
    `${METER_COOKIE_NAME}=${token}`,
    `Max-Age=${METER_TTL_SECONDS}`,
    "Path=/",
    "HttpOnly",
    "Secure",
    "SameSite=Lax",
  ].join("; ");
}
