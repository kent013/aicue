import { mkdir, open, readFile, rm, stat } from "node:fs/promises";
import { dirname } from "node:path";

/**
 * File lock that serialises refresh-token rotation across CLI processes
 * (T715 Phase 3).
 *
 * Refresh tokens are single-use under rotation, so two CLI processes
 * refreshing concurrently would each invalidate the other's new refresh
 * token. The lock is acquired with `O_CREAT | O_EXCL` (open flag `wx`).
 * After acquiring it the caller (the token provider) re-reads the stored
 * token to detect a refresh another process already performed.
 *
 * - retry: 100 ms × 100 (~10 s max wait)
 * - stale takeover: lock older than 30 s is assumed to be a crashed
 *   holder's leftover and removed (30 s is generous enough to avoid false
 *   takeover on slow machines).
 * - the lock file records `{pid, acquired_at}` JSON for debugging.
 */

const RETRY_INTERVAL_MS = 100;
const MAX_RETRIES = 100;
const STALE_LOCK_MS = 30_000;

function lockPathFor(basePath: string): string {
    return `${basePath}.lock`;
}

async function tryAcquire(lockPath: string): Promise<boolean> {
    try {
        await mkdir(dirname(lockPath), { recursive: true, mode: 0o700 });
        const handle = await open(lockPath, "wx", 0o600);
        await handle.writeFile(
            JSON.stringify({
                pid: process.pid,
                acquired_at: new Date().toISOString(),
            }),
        );
        await handle.close();
        return true;
    } catch {
        return false;
    }
}

async function isStale(lockPath: string): Promise<boolean> {
    try {
        const raw = await readFile(lockPath, "utf-8");
        const parsed = JSON.parse(raw) as { acquired_at?: unknown };
        if (typeof parsed.acquired_at === "string") {
            const acquiredAt = Date.parse(parsed.acquired_at);
            if (!Number.isNaN(acquiredAt)) {
                return Date.now() - acquiredAt > STALE_LOCK_MS;
            }
        }
    } catch {
        // fall through to mtime
    }
    try {
        const info = await stat(lockPath);
        return Date.now() - info.mtimeMs > STALE_LOCK_MS;
    } catch {
        // lock vanished = acquirable
        return false;
    }
}

async function sleep(ms: number): Promise<void> {
    await new Promise<void>((resolve) => setTimeout(resolve, ms));
}

/**
 * Run `fn` while holding the lock. Throws if the lock cannot be acquired
 * within the retry budget (the caller decides whether to retry or surface
 * an explicit error).
 */
export async function withRefreshLock<T>(
    basePath: string,
    fn: () => Promise<T>,
): Promise<T> {
    const lockPath = lockPathFor(basePath);

    let acquired = false;
    for (let i = 0; i < MAX_RETRIES; i++) {
        if (await tryAcquire(lockPath)) {
            acquired = true;
            break;
        }
        if (await isStale(lockPath)) {
            await rm(lockPath, { force: true });
            continue;
        }
        await sleep(RETRY_INTERVAL_MS);
    }

    if (!acquired) {
        throw new Error(
            "Could not acquire the credential refresh lock "
                + "(another process is holding it).",
        );
    }

    try {
        return await fn();
    } finally {
        await rm(lockPath, { force: true });
    }
}
