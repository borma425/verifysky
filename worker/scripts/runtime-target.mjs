#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";

export function resolveRuntimeTarget(argv = process.argv.slice(2)) {
  loadDotEnvIfNeeded();
  let envName = "production";
  const forwardedArgs = [];

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    if (arg === "--env") {
      const next = argv[index + 1];
      if (!next) {
        throw new Error("Missing value for --env. Supported values: production, staging.");
      }
      envName = normalizeEnvName(next);
      index += 1;
      continue;
    }

    if (arg.startsWith("--env=")) {
      envName = normalizeEnvName(arg.slice("--env=".length));
      continue;
    }

    forwardedArgs.push(arg);
  }

  return {
    envName,
    forwardedArgs,
    d1DatabaseName: defaultTargets()[envName].d1DatabaseName,
    workerScriptName: defaultTargets()[envName].workerScriptName,
    wranglerEnvArgs: [...defaultTargets()[envName].wranglerEnvArgs],
  };
}

function defaultTargets() {
  return {
    production: {
      d1DatabaseName: process.env.D1_DATABASE_NAME || "VERIFY_SKY_PRODUCTION_DB",
      workerScriptName: process.env.EDGE_SHIELD_WORKER_NAME || "verifysky-edge",
      wranglerEnvArgs: [],
    },
    staging: {
      d1DatabaseName: process.env.STAGING_D1_DATABASE_NAME || "VERIFY_SKY_STAGING_DB_V2",
      workerScriptName: process.env.STAGING_EDGE_SHIELD_WORKER_NAME || "verifysky-edge-staging",
      wranglerEnvArgs: ["--env", "staging"],
    },
  };
}

function loadDotEnvIfNeeded() {
  const candidates = [
    process.env.EDGE_SHIELD_DOTENV_PATH || "",
    path.resolve(process.cwd(), ".env"),
    path.resolve(process.cwd(), "../dashboard/.env"),
  ].filter(Boolean);

  for (const envPath of candidates) {
    if (!fs.existsSync(envPath)) {
      continue;
    }

    const raw = fs.readFileSync(envPath, "utf8");
    for (const line of raw.split(/\r?\n/)) {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith("#")) {
        continue;
      }
      const index = trimmed.indexOf("=");
      if (index <= 0) {
        continue;
      }
      const key = trimmed.slice(0, index).trim();
      let value = trimmed.slice(index + 1).trim();
      if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
        value = value.slice(1, -1);
      }
      if (!(key in process.env)) {
        process.env[key] = value;
      }
    }
  }
}

function normalizeEnvName(value) {
  const normalized = String(value || "").trim().toLowerCase();
  if (normalized === "" || normalized === "production" || normalized === "prod") {
    return "production";
  }
  if (normalized === "staging" || normalized === "stage") {
    return "staging";
  }

  throw new Error(`Unsupported runtime environment "${value}". Supported values: production, staging.`);
}
