import {
    closeSync,
    fsyncSync,
    openSync,
    renameSync,
    writeFileSync,
} from "node:fs";

/**
 * tmp write -> fsync -> atomic rename. Same-filesystem is required; cross-
 * device rename is intentionally not handled.
 */
export function atomicWriteFile(
    path: string,
    content: string,
    mode: number = 0o600,
): void {
    writeThenRename(path, content, mode);
}

/** Binary variant used by the UXI1 encrypted file-store. */
export function atomicWriteFileBinary(
    path: string,
    content: Uint8Array,
    mode: number = 0o600,
): void {
    writeThenRename(path, content, mode);
}

function writeThenRename(
    path: string,
    content: string | Uint8Array,
    mode: number,
): void {
    const tmp = `${path}.${String(process.pid)}.tmp`;
    if (typeof content === "string") {
        writeFileSync(tmp, content, { encoding: "utf-8", mode });
    } else {
        writeFileSync(tmp, content, { mode });
    }
    const fd = openSync(tmp, "r");
    try {
        fsyncSync(fd);
    } finally {
        closeSync(fd);
    }
    renameSync(tmp, path);
}
