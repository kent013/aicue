import { ENV } from "../../src/branding.js";
import {
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
    statSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { FileStore } from "../../src/credential/file-store.js";
import { deriveProfileHash12 } from "../../src/credential/key-derivation.js";
import { MasterKeyRegistry } from "../../src/credential/master-key-registry.js";
import {
    ENV_CREDENTIAL_KEY,
    ENV_MASTER_PASSWORD,
} from "../../src/credential/master-password.js";
import { setGlobalAllowPlaintextFlag } from "../../src/runtime-state.js";
import {
    UXI2_MAGIC,
    UXI2_VERSION,
    UXI2_CIPHER_AES_256_GCM,
} from "../../src/credential/encryption.js";

let tmpRoot: string;
const origEnv = {
    key: process.env[ENV_CREDENTIAL_KEY],
    pw: process.env[ENV_MASTER_PASSWORD],
    allow: process.env[ENV.ALLOW_PLAINTEXT],
    ci: process.env["CI"],
};

function mockExit(): () => void {
    const spy = vi
        .spyOn(process, "exit")
        .mockImplementation((code?: string | number | null): never => {
            throw new Error(`EXIT:${String(code)}`);
        });
    return () => spy.mockRestore();
}

function silenceStderr(): () => void {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    return () => spy.mockRestore();
}

beforeEach(() => {
    tmpRoot = mkdtempSync(join(tmpdir(), "uxi-fs-"));
    delete process.env[ENV_CREDENTIAL_KEY];
    delete process.env[ENV_MASTER_PASSWORD];
    delete process.env[ENV.ALLOW_PLAINTEXT];
    // B2 / T144: the plaintext opt-in is refused when CI=true. Vitest
    // runs inherit the parent env which may carry CI=true (e.g. on a
    // GitHub Actions runner). Clear it here so the opt-in path is
    // reachable during tests; we restore it on afterEach.
    delete process.env["CI"];
    setGlobalAllowPlaintextFlag(false);
});
afterEach(() => {
    rmSync(tmpRoot, { recursive: true, force: true });
    for (const [k, v] of [
        [ENV_CREDENTIAL_KEY, origEnv.key],
        [ENV_MASTER_PASSWORD, origEnv.pw],
        [`${ENV.ALLOW_PLAINTEXT}`, origEnv.allow],
        ["CI", origEnv.ci],
    ] as const) {
        if (v !== undefined) process.env[k] = v;
        else delete process.env[k];
    }
    setGlobalAllowPlaintextFlag(false);
});

const ORIGIN = "https://example.com:443";
const PROFILE = "prod";

async function makeEncryptedStore(): Promise<{
    store: FileStore;
    registry: MasterKeyRegistry;
}> {
    const registry = new MasterKeyRegistry();
    process.env[ENV_CREDENTIAL_KEY] = Buffer.alloc(32)
        .fill(0x33)
        .toString("base64");
    await registry.ensure(ORIGIN, PROFILE, { env: process.env, isTty: false });
    const store = new FileStore(tmpRoot, registry);
    return { store, registry };
}

describe("FileStore — encrypted path (UXI2)", () => {
    it("writes and reads back round-trip", async () => {
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "k1");
        expect(store.read(ORIGIN, PROFILE, "apikey", "")).toBe("k1");
    });

    it("writes a file with .enc extension and UXI2 magic + header", async () => {
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "k1");
        const ph12 = deriveProfileHash12(ORIGIN, PROFILE);
        const enc = join(tmpRoot, ph12, "apikey.enc");
        const bytes = readFileSync(enc);
        expect(bytes[0]).toBe(UXI2_MAGIC[0]);
        expect(bytes[1]).toBe(UXI2_MAGIC[1]);
        expect(bytes[2]).toBe(UXI2_MAGIC[2]);
        expect(bytes[3]).toBe(UXI2_MAGIC[3]);
        expect(bytes[4]).toBe(UXI2_VERSION);
        expect(bytes[5]).toBe(UXI2_CIPHER_AES_256_GCM);
    });

    it("writes files with permission 0600 and directory 0700", async () => {
        if (process.platform === "win32") return;
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "k1");
        const ph12 = deriveProfileHash12(ORIGIN, PROFILE);
        const enc = join(tmpRoot, ph12, "apikey.enc");
        expect(statSync(enc).mode & 0o777).toBe(0o600);
        expect(statSync(join(tmpRoot, ph12)).mode & 0o777).toBe(0o700);
    });

    it("delete() removes the .enc file", async () => {
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "k1");
        store.delete(ORIGIN, PROFILE, "apikey", "");
        expect(store.read(ORIGIN, PROFILE, "apikey", "")).toBeNull();
    });

    it("no .salt sidecar file is created (T148: per-file header salt)", async () => {
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "k1");
        const ph12 = deriveProfileHash12(ORIGIN, PROFILE);
        const entries = readdirSync(join(tmpRoot, ph12));
        expect(entries).not.toContain(".salt");
        expect(entries).toContain("apikey.enc");
    });

    it("listItemsOnDisk returns kind+id for written items", async () => {
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "k1");
        store.write(ORIGIN, PROFILE, "credential", "oauth", "c1");
        const items = store.listItemsOnDisk(ORIGIN, PROFILE);
        expect(items).toHaveLength(2);
        const kinds = items.map((i) => i.kind).sort();
        expect(kinds).toEqual(["apikey", "credential"]);
    });

    it("fails read with exit 33 when password is wrong", async () => {
        const reg1 = new MasterKeyRegistry();
        process.env[ENV_MASTER_PASSWORD] = "correct";
        await reg1.ensure(ORIGIN, PROFILE, {
            env: process.env,
            isTty: false,
        });
        const store1 = new FileStore(tmpRoot, reg1);
        store1.write(ORIGIN, PROFILE, "apikey", "", "secret");
        const reg2 = new MasterKeyRegistry();
        process.env[ENV_MASTER_PASSWORD] = "wrong";
        await reg2.ensure(ORIGIN, PROFILE, {
            env: process.env,
            isTty: false,
        });
        const store2 = new FileStore(tmpRoot, reg2);
        const restore = mockExit();
        const restoreErr = silenceStderr();
        try {
            expect(() => store2.read(ORIGIN, PROFILE, "apikey", "")).toThrow(
                /EXIT:33/,
            );
        } finally {
            restoreErr();
            restore();
        }
    });

    it("clearProfile wipes the profile directory", async () => {
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "k1");
        store.clearProfile(ORIGIN, PROFILE);
        expect(store.read(ORIGIN, PROFILE, "apikey", "")).toBeNull();
    });

    it("fails with exit 32 when no env is set and deriveKey is called", () => {
        const registry = new MasterKeyRegistry();
        const store = new FileStore(tmpRoot, registry);
        const restore = mockExit();
        const restoreErr = silenceStderr();
        try {
            expect(() => store.write(ORIGIN, PROFILE, "apikey", "", "x")).toThrow(
                /EXIT:32/,
            );
        } finally {
            restoreErr();
            restore();
        }
    });

    it(`write()→read() with ${ENV.MASTER_PASSWORD} (scrypt) round-trips`, async () => {
        const registry = new MasterKeyRegistry();
        process.env[ENV_MASTER_PASSWORD] = "scrypt-password";
        await registry.ensure(ORIGIN, PROFILE, {
            env: process.env,
            isTty: false,
        });
        const store = new FileStore(tmpRoot, registry);
        store.write(ORIGIN, PROFILE, "credential", "siteA", "{json:1}");
        expect(store.read(ORIGIN, PROFILE, "credential", "siteA")).toBe(
            "{json:1}",
        );
    });

    it("detects salt tamper on read even under env-key source (AAD-bound)", async () => {
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "secret");
        const ph12 = deriveProfileHash12(ORIGIN, PROFILE);
        const encPath = join(tmpRoot, ph12, "apikey.enc");
        const bytes = readFileSync(encPath);
        // Flip one bit in the salt region (bytes [6..22)).
        bytes[6] = (bytes[6] ?? 0) ^ 0x01;
        const { writeFileSync } = await import("node:fs");
        writeFileSync(encPath, bytes, { mode: 0o600 });
        const restore = mockExit();
        const restoreErr = silenceStderr();
        try {
            expect(() => store.read(ORIGIN, PROFILE, "apikey", "")).toThrow(
                /EXIT:33/,
            );
        } finally {
            restoreErr();
            restore();
        }
    });

    it("different writes produce different salts in the header", async () => {
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "a", "v1");
        store.write(ORIGIN, PROFILE, "apikey", "b", "v2");
        const ph12 = deriveProfileHash12(ORIGIN, PROFILE);
        const fileA = readFileSync(join(tmpRoot, ph12, "apikey_a.enc"));
        const fileB = readFileSync(join(tmpRoot, ph12, "apikey_b.enc"));
        // salt lives at bytes [6..22)
        const saltA = fileA.subarray(6, 22).toString("hex");
        const saltB = fileB.subarray(6, 22).toString("hex");
        expect(saltA).not.toBe(saltB);
    });
});

describe("FileStore — plaintext opt-in (T114 contract)", () => {
    it("write fails without opt-in and without key material", () => {
        const restore = mockExit();
        const restoreErr = silenceStderr();
        try {
            const registry = new MasterKeyRegistry();
            const fs = new FileStore(tmpRoot, registry);
            // No key loaded, no opt-in → registry.deriveKey exits 32.
            expect(() => fs.write(ORIGIN, PROFILE, "apikey", "", "k")).toThrow(
                /EXIT:(18|32)/,
            );
        } finally {
            restoreErr();
            restore();
        }
    });

    it("writes and reads plaintext when opt-in flag is set", () => {
        setGlobalAllowPlaintextFlag(true);
        const fs = new FileStore(tmpRoot);
        fs.write(ORIGIN, PROFILE, "apikey", "", "k1");
        expect(fs.read(ORIGIN, PROFILE, "apikey", "")).toBe("k1");
    });

    it("plaintext files use the .dat extension", () => {
        setGlobalAllowPlaintextFlag(true);
        const fs = new FileStore(tmpRoot);
        fs.write(ORIGIN, PROFILE, "apikey", "", "k1");
        const ph12 = deriveProfileHash12(ORIGIN, PROFILE);
        expect(readdirSync(join(tmpRoot, ph12))).toContain("apikey.dat");
    });

    it("encrypted file wins over a stale plaintext twin on read", async () => {
        // First: plaintext opt-in → .dat file written.
        setGlobalAllowPlaintextFlag(true);
        const fs1 = new FileStore(tmpRoot);
        fs1.write(ORIGIN, PROFILE, "apikey", "", "plain-value");
        setGlobalAllowPlaintextFlag(false);

        // Then: switch to encrypted mode and overwrite with a new value.
        const { store } = await makeEncryptedStore();
        store.write(ORIGIN, PROFILE, "apikey", "", "enc-value");
        const ph12 = deriveProfileHash12(ORIGIN, PROFILE);
        const entries = readdirSync(join(tmpRoot, ph12));
        // .enc exists, .dat twin removed by the encrypted write path.
        expect(entries).toContain("apikey.enc");
        expect(entries).not.toContain("apikey.dat");
        expect(store.read(ORIGIN, PROFILE, "apikey", "")).toBe("enc-value");
    });

    it("plaintext file refused at read time when opt-in is revoked (exit 18)", () => {
        setGlobalAllowPlaintextFlag(true);
        process.env[ENV.ALLOW_PLAINTEXT] = "1";
        const fs = new FileStore(tmpRoot);
        fs.write(ORIGIN, PROFILE, "apikey", "", "k1");
        delete process.env[ENV.ALLOW_PLAINTEXT];
        setGlobalAllowPlaintextFlag(false);
        const restore = mockExit();
        const restoreErr = silenceStderr();
        try {
            expect(() => fs.read(ORIGIN, PROFILE, "apikey", "")).toThrow(
                /EXIT:18/,
            );
        } finally {
            restoreErr();
            restore();
        }
    });
});
