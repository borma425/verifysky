#!/usr/bin/env node
import { execFileSync } from "node:child_process";
import { resolveRuntimeTarget } from "./runtime-target.mjs";

const { envName, forwardedArgs, d1DatabaseName, wranglerEnvArgs } = resolveRuntimeTarget();
const useLocal = forwardedArgs.includes("--local");

const wranglerArgs = [
  "wrangler",
  "d1",
  "execute",
  d1DatabaseName,
  ...wranglerEnvArgs,
  useLocal ? "--local" : "--remote",
  "--file",
  "./schema.sql",
];

console.log(`[db:init] Applying schema to ${envName} (${useLocal ? "local" : "remote"}) D1: ${d1DatabaseName}`);
execFileSync("npx", wranglerArgs, {
  cwd: process.cwd(),
  stdio: "inherit",
  timeout: 120000,
});
