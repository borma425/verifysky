// ============================================================================
// Ultimate Edge Shield — Cryptographic Utilities
// Pure Web Crypto API (crypto.subtle) — no external dependencies.
//
// Functions:
//   generateSignature()   — HMAC-SHA256 signing (hex output)
//   verifySignature()     — Constant-time HMAC-SHA256 verification
//   createSessionToken()  — Custom JWT creation (Base64URL header.payload.sig)
//   verifySessionToken()  — JWT verification with expiry + claim extraction
//   hashFingerprint()     — SHA-256 hash of device fingerprint components
//   generateNonce()       — Cryptographic random nonce (hex)
//   timeSafeEqual()       — Constant-time string comparison
// ============================================================================

import type { SessionTokenClaims } from "./types";

// ---------------------------------------------------------------------------
// Internal: Text Encoding Helpers
// ---------------------------------------------------------------------------

const ENCODER = new TextEncoder();
const DECODER = new TextDecoder();

/** Converts a hex string to Uint8Array */
function hexToBytes(hex: string): Uint8Array {
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < hex.length; i += 2) {
    bytes[i / 2] = parseInt(hex.substring(i, i + 2), 16);
  }
  return bytes;
}

/** Converts an ArrayBuffer to a hex string */
function bufferToHex(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer);
  let hex = "";
  for (let i = 0; i < bytes.length; i++) {
    hex += bytes[i].toString(16).padStart(2, "0");
  }
  return hex;
}

/** Encodes a string to URL-safe Base64 (no padding) */
function base64UrlEncode(input: string): string {
  const bytes = ENCODER.encode(input);
  const binString = Array.from(bytes, (b) => String.fromCharCode(b)).join("");
  return btoa(binString)
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");
}

/** Decodes a URL-safe Base64 string */
function base64UrlDecode(input: string): string {
  let base64 = input.replace(/-/g, "+").replace(/_/g, "/");
  // Restore padding
  const pad = base64.length % 4;
  if (pad === 2) base64 += "==";
  else if (pad === 3) base64 += "=";

  const binString = atob(base64);
  const bytes = Uint8Array.from(binString, (c) => c.charCodeAt(0));
  return DECODER.decode(bytes);
}

// ---------------------------------------------------------------------------
// Internal: CryptoKey Import
// ---------------------------------------------------------------------------

/** Imports a secret string as an HMAC-SHA256 CryptoKey */
async function importHmacKey(secret: string): Promise<CryptoKey> {
  return crypto.subtle.importKey(
    "raw",
    ENCODER.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign", "verify"]
  );
}

// ---------------------------------------------------------------------------
// Public: HMAC-SHA256 Signature
// ---------------------------------------------------------------------------

/**
 * Generates an HMAC-SHA256 signature of the given payload.
 * Returns a lowercase hex string (64 characters).
 */
export async function generateSignature(
  payload: string,
  secret: string
): Promise<string> {
  const key = await importHmacKey(secret);
  const signature = await crypto.subtle.sign(
    "HMAC",
    key,
    ENCODER.encode(payload)
  );
  return bufferToHex(signature);
}

/**
 * Verifies an HMAC-SHA256 signature using constant-time comparison.
 * Returns true only if the signature is valid.
 */
export async function verifySignature(
  payload: string,
  signature: string,
  secret: string
): Promise<boolean> {
  const key = await importHmacKey(secret);
  const sigBytes = hexToBytes(signature);
  return crypto.subtle.verify("HMAC", key, sigBytes, ENCODER.encode(payload));
}

// ---------------------------------------------------------------------------
// Public: Session Token (Custom JWT)
// ---------------------------------------------------------------------------

/** Static JWT header — always HMAC-SHA256 */
const JWT_HEADER = base64UrlEncode(
  JSON.stringify({ alg: "HS256", typ: "JWT" })
);

/**
 * Creates a cryptographically signed session token (JWT format).
 * Format: Base64URL(header).Base64URL(payload).Base64URL(signature)
 *
 * The token is bound to the user's IP and fingerprint hash via claims.
 */
export async function createSessionToken(
  claims: SessionTokenClaims,
  secret: string
): Promise<string> {
  const payloadB64 = base64UrlEncode(JSON.stringify(claims));
  const signingInput = `${JWT_HEADER}.${payloadB64}`;

  const key = await importHmacKey(secret);
  const sigBuffer = await crypto.subtle.sign(
    "HMAC",
    key,
    ENCODER.encode(signingInput)
  );

  // Convert signature to Base64URL
  const sigBytes = new Uint8Array(sigBuffer);
  const sigBinString = Array.from(sigBytes, (b) =>
    String.fromCharCode(b)
  ).join("");
  const sigB64 = btoa(sigBinString)
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");

  return `${signingInput}.${sigB64}`;
}

/**
 * Verifies a session token and returns the decoded claims.
 * Returns null if the token is invalid, expired, or tampered with.
 *
 * Verification steps:
 *   1. Structural validation (3 dot-separated parts)
 *   2. HMAC-SHA256 signature verification (constant-time)
 *   3. Expiration check (exp claim vs current time)
 *   4. Claims parsing and return
 */
export async function verifySessionToken(
  token: string,
  secret: string
): Promise<SessionTokenClaims | null> {
  try {
    const parts = token.split(".");
    if (parts.length !== 3) return null;

    const [header, payload, signature] = parts;
    const signingInput = `${header}.${payload}`;

    // Decode the signature from Base64URL to bytes
    let sigBase64 = signature.replace(/-/g, "+").replace(/_/g, "/");
    const pad = sigBase64.length % 4;
    if (pad === 2) sigBase64 += "==";
    else if (pad === 3) sigBase64 += "=";

    const sigBinString = atob(sigBase64);
    const sigBytes = Uint8Array.from(sigBinString, (c) => c.charCodeAt(0));

    // Constant-time signature verification via Web Crypto
    const key = await importHmacKey(secret);
    const isValid = await crypto.subtle.verify(
      "HMAC",
      key,
      sigBytes,
      ENCODER.encode(signingInput)
    );

    if (!isValid) return null;

    // Decode and parse claims
    const claimsJson = base64UrlDecode(payload);
    const claims: SessionTokenClaims = JSON.parse(claimsJson);

    // Check expiration
    const now = Math.floor(Date.now() / 1000);
    if (claims.exp && claims.exp < now) return null;

    return claims;
  } catch {
    // Any parsing/decoding error means the token is invalid
    return null;
  }
}

// ---------------------------------------------------------------------------
// Public: Fingerprint Hashing
// ---------------------------------------------------------------------------

/**
 * Creates a SHA-256 hash of device fingerprint components.
 * Components are joined with a null byte separator to prevent
 * collision attacks (e.g., ["ab", "cd"] vs ["a", "bcd"]).
 *
 * Returns a lowercase hex string (64 characters).
 */
export async function hashFingerprint(
  components: string[]
): Promise<string> {
  const joined = components.join("\0");
  const hash = await crypto.subtle.digest("SHA-256", ENCODER.encode(joined));
  return bufferToHex(hash);
}

// ---------------------------------------------------------------------------
// Public: Nonce Generation
// ---------------------------------------------------------------------------

/**
 * Generates a cryptographically secure random nonce.
 * Default: 32 bytes of entropy (64-character hex string).
 */
export function generateNonce(byteLength: number = 32): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return bufferToHex(bytes.buffer);
}

// ---------------------------------------------------------------------------
// Public: Constant-Time String Comparison
// ---------------------------------------------------------------------------

/**
 * Compares two strings in constant time to prevent timing attacks.
 * Both strings are hashed first to normalize length, then compared
 * byte-by-byte with XOR accumulation.
 *
 * This is used for comparing tokens, signatures, and other secrets
 * where timing side-channels could leak information.
 */
export async function timeSafeEqual(a: string, b: string): Promise<boolean> {
  const keyData = ENCODER.encode("timing-safe-comparison-key");
  const key = await crypto.subtle.importKey(
    "raw",
    keyData,
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"]
  );

  const [hashA, hashB] = await Promise.all([
    crypto.subtle.sign("HMAC", key, ENCODER.encode(a)),
    crypto.subtle.sign("HMAC", key, ENCODER.encode(b)),
  ]);

  const bytesA = new Uint8Array(hashA);
  const bytesB = new Uint8Array(hashB);

  if (bytesA.length !== bytesB.length) return false;

  let result = 0;
  for (let i = 0; i < bytesA.length; i++) {
    result |= bytesA[i] ^ bytesB[i];
  }
  return result === 0;
}
