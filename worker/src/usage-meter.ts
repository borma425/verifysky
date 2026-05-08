import type { Env } from "./types";

type MutableEnv = Env & {
  DB: D1Database;
  SESSION_KV: KVNamespace;
};

type UsageCounters = {
  requests: number;
  d1RowsRead: number;
  d1RowsWritten: number;
  d1QueryCount: number;
  kvReads: number;
  kvWrites: number;
  kvDeletes: number;
  kvLists: number;
  kvWriteBytes: number;
};

const meters = new WeakMap<object, UsageMeter>();

export function createMeteredEnv(env: Env): Env {
  const meter = new UsageMeter(env);
  const meteredEnv = {
    ...env,
    DB: meter.wrapD1(env.DB),
    SESSION_KV: meter.wrapKV(env.SESSION_KV),
  } as MutableEnv;

  meters.set(meteredEnv, meter);

  return meteredEnv;
}

export function bindUsageTenant(env: Env, tenantId?: string | null, domain?: string | null): void {
  meters.get(env)?.bindTenant(tenantId, domain);
}

export function flushUsageMeter(env: Env, response: Response): void {
  meters.get(env)?.flush(response);
}

class UsageMeter {
  private readonly counters: UsageCounters = {
    requests: 1,
    d1RowsRead: 0,
    d1RowsWritten: 0,
    d1QueryCount: 0,
    kvReads: 0,
    kvWrites: 0,
    kvDeletes: 0,
    kvLists: 0,
    kvWriteBytes: 0,
  };

  private tenantId = "";
  private domain = "";
  private flushed = false;

  constructor(private readonly env: Env) {}

  bindTenant(tenantId?: string | null, domain?: string | null): void {
    const normalizedTenantId = String(tenantId || "").trim();
    if (normalizedTenantId !== "") {
      this.tenantId = normalizedTenantId;
    }

    const normalizedDomain = String(domain || "").trim().toLowerCase();
    if (normalizedDomain !== "") {
      this.domain = normalizedDomain;
    }
  }

  wrapD1(db: D1Database): D1Database {
    const meter = this;

    return new Proxy(db as unknown as Record<PropertyKey, unknown>, {
      get(target, prop, receiver) {
        if (prop === "prepare") {
          return (sql: string): unknown => meter.wrapStatement(
            (target.prepare as (value: string) => unknown).call(target, sql),
            sql
          );
        }

        if (prop === "batch") {
          return async (statements: unknown[]): Promise<unknown> => {
            meter.counters.d1QueryCount += statements.length;
            const result = await (target.batch as (value: unknown[]) => Promise<unknown>).call(target, statements);
            meter.recordD1Result(result);

            return result;
          };
        }

        if (prop === "exec") {
          return async (...args: unknown[]): Promise<unknown> => {
            meter.counters.d1QueryCount += 1;
            const result = await (target.exec as (...values: unknown[]) => Promise<unknown>).apply(target, args);
            meter.recordD1Result(result);

            return result;
          };
        }

        return Reflect.get(target, prop, receiver);
      },
    }) as unknown as D1Database;
  }

  wrapKV(kv: KVNamespace): KVNamespace {
    const meter = this;

    return new Proxy(kv as unknown as Record<PropertyKey, unknown>, {
      get(target, prop, receiver) {
        if (prop === "get" || prop === "getWithMetadata") {
          return async (...args: unknown[]): Promise<unknown> => {
            meter.counters.kvReads += 1;

            return (target[prop] as (...values: unknown[]) => Promise<unknown>).apply(target, args);
          };
        }

        if (prop === "put") {
          return async (...args: unknown[]): Promise<unknown> => {
            meter.counters.kvWrites += 1;
            meter.counters.kvWriteBytes += estimateBytes(args[1]);

            return (target.put as (...values: unknown[]) => Promise<unknown>).apply(target, args);
          };
        }

        if (prop === "delete") {
          return async (...args: unknown[]): Promise<unknown> => {
            meter.counters.kvDeletes += 1;

            return (target.delete as (...values: unknown[]) => Promise<unknown>).apply(target, args);
          };
        }

        if (prop === "list") {
          return async (...args: unknown[]): Promise<unknown> => {
            meter.counters.kvLists += 1;

            return (target.list as (...values: unknown[]) => Promise<unknown>).apply(target, args);
          };
        }

        return Reflect.get(target, prop, receiver);
      },
    }) as unknown as KVNamespace;
  }

  flush(response: Response): void {
    if (this.flushed || this.tenantId === "" || this.domain === "" || !this.env.USAGE_ANALYTICS) {
      return;
    }

    this.flushed = true;

    try {
      this.env.USAGE_ANALYTICS.writeDataPoint({
        indexes: [this.tenantId],
        blobs: [
          this.domain,
          this.environmentName(),
          this.outcomeFor(response.status),
        ],
        doubles: [
          this.counters.requests,
          this.counters.d1RowsRead,
          this.counters.d1RowsWritten,
          this.counters.d1QueryCount,
          this.counters.kvReads,
          this.counters.kvWrites,
          this.counters.kvDeletes,
          this.counters.kvLists,
          this.counters.kvWriteBytes,
        ],
      });
    } catch {
      // Usage telemetry must never affect request handling.
    }
  }

  private wrapStatement(statement: unknown, sql: string): unknown {
    const meter = this;
    const target = statement as Record<PropertyKey, unknown>;

    return new Proxy(target, {
      get(statementTarget, prop, receiver) {
        if (prop === "bind") {
          return (...args: unknown[]): unknown => meter.wrapStatement(
            (statementTarget.bind as (...values: unknown[]) => unknown).apply(statementTarget, args),
            sql
          );
        }

        if (prop === "all" || prop === "first" || prop === "raw" || prop === "run") {
          return async (...args: unknown[]): Promise<unknown> => {
            meter.counters.d1QueryCount += 1;
            const result = await (statementTarget[prop] as (...values: unknown[]) => Promise<unknown>).apply(statementTarget, args);
            meter.recordStatementResult(sql, prop, result);

            return result;
          };
        }

        return Reflect.get(statementTarget, prop, receiver);
      },
    });
  }

  private recordStatementResult(sql: string, method: PropertyKey, result: unknown): void {
    const keyword = leadingSqlKeyword(sql);
    const resultRows = resultRowCount(result);
    const meta = resultMeta(result);

    if (typeof meta.rows_read === "number") {
      this.counters.d1RowsRead += Math.max(0, meta.rows_read);
    } else if (keyword === "SELECT" || keyword === "WITH") {
      this.counters.d1RowsRead += method === "first" ? Math.min(1, resultRows || 1) : resultRows;
    }

    if (typeof meta.rows_written === "number") {
      this.counters.d1RowsWritten += Math.max(0, meta.rows_written);
    } else if (["INSERT", "UPDATE", "DELETE", "REPLACE"].includes(keyword)) {
      this.counters.d1RowsWritten += Math.max(1, resultRows);
    }
  }

  private recordD1Result(result: unknown): void {
    if (Array.isArray(result)) {
      for (const row of result) {
        this.recordD1Result(row);
      }

      return;
    }

    const meta = resultMeta(result);
    if (typeof meta.rows_read === "number") {
      this.counters.d1RowsRead += Math.max(0, meta.rows_read);
    }
    if (typeof meta.rows_written === "number") {
      this.counters.d1RowsWritten += Math.max(0, meta.rows_written);
    }
  }

  private environmentName(): string {
    return String(this.env.ES_TEST_MODE || "").toLowerCase() === "on" ? "staging" : "production";
  }

  private outcomeFor(status: number): string {
    if (status >= 500) return "error";
    if (status >= 400) return "blocked";
    if (status >= 300) return "redirect";

    return "pass";
  }
}

function leadingSqlKeyword(sql: string): string {
  return sql.trim().split(/\s+/, 1)[0]?.toUpperCase() || "";
}

function resultMeta(result: unknown): Record<string, number> {
  if (!result || typeof result !== "object") {
    return {};
  }

  const meta = (result as { meta?: unknown }).meta;

  return meta && typeof meta === "object" ? meta as Record<string, number> : {};
}

function resultRowCount(result: unknown): number {
  if (Array.isArray(result)) {
    return result.length;
  }

  if (!result || typeof result !== "object") {
    return result === null || result === undefined ? 0 : 1;
  }

  const rows = (result as { results?: unknown }).results;
  if (Array.isArray(rows)) {
    return rows.length;
  }

  return 1;
}

function estimateBytes(value: unknown): number {
  if (typeof value === "string") {
    return value.length;
  }

  if (value instanceof ArrayBuffer) {
    return value.byteLength;
  }

  if (ArrayBuffer.isView(value)) {
    return value.byteLength;
  }

  if (value instanceof ReadableStream) {
    return 0;
  }

  try {
    return JSON.stringify(value)?.length || 0;
  } catch {
    return 0;
  }
}
