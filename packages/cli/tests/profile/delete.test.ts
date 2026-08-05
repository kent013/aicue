import { existsSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { BIN_NAME, ENV } from "../../src/branding.js";
import type { ProfileEntry, RootConfigInput } from "../../src/config/schema.js";
import { saveConfigToPath } from "../../src/config/saver.js";
import { CredentialStoreError } from "../../src/credential/errors.js";
import { FileStore } from "../../src/credential/file-store.js";
import { deriveProfileHash12 } from "../../src/credential/key-derivation.js";
import { KeychainStore } from "../../src/credential/keychain.js";
import {
    getGlobalMasterKeyRegistry,
    MasterKeyRegistry,
    resetGlobalMasterKeyRegistryForTests,
} from "../../src/credential/master-key-registry.js";
import { CredentialStore } from "../../src/credential/store.js";
import { ExitCode } from "../../src/exit-codes.js";
import {
    deleteOAuthToken,
    readOAuthToken,
    writeOAuthToken,
    type OAuthTokenBundle,
} from "../../src/oauth/token-store.js";
import { canonicalOrigin } from "../../src/profile/canonical-origin.js";
import {
    executeProfileDeletion,
    planProfileDeletion,
} from "../../src/profile/delete.js";
import { ProfileResolutionError } from "../../src/profile/errors.js";
import {
    FileProfileWriter,
    type ProfileWriter,
} from "../../src/profile/writer.js";
import { setGlobalAllowPlaintextFlag } from "../../src/runtime-state.js";

/**
 * `profile:delete` のロジック層テスト。
 *
 * 3 backend (keychain / file-encrypted / file-plaintext) を横断して
 * 「credential が確実に落ちる」「config が壊れない」「消してはいけないものを
 * 消さない」を固定する。CLI としての契約 (exit code / プロンプト順序) は
 * tests/commands/profile/delete.test.ts が担当する。
 */

type BackendName = "keychain" | "file-encrypted" | "file-plaintext";

const BACKENDS: readonly BackendName[] = [
    "keychain",
    "file-encrypted",
    "file-plaintext",
];

const API_URL_A = "https://a.example.com";
const API_URL_B = "https://b.example.com";
const ORIGIN_A = canonicalOrigin(API_URL_A);
const ORIGIN_B = canonicalOrigin(API_URL_B);

// ---------------------------------------------------------------------------
// env の退避・復帰 (file-store.test.ts の作法に合わせる)
// ---------------------------------------------------------------------------

const origEnv = {
    key: process.env[ENV.CREDENTIAL_KEY],
    pw: process.env[ENV.MASTER_PASSWORD],
    allow: process.env[ENV.ALLOW_PLAINTEXT],
    disableKeychain: process.env[ENV.DISABLE_KEYCHAIN],
    ci: process.env["CI"],
};

let tmpDirs: string[] = [];

beforeEach(() => {
    tmpDirs = [];
    delete process.env[ENV.CREDENTIAL_KEY];
    delete process.env[ENV.MASTER_PASSWORD];
    delete process.env[ENV.ALLOW_PLAINTEXT];
    delete process.env["CI"];
    // 既定は「keychain 無効」。keychain ケースだけが自スコープで解除する。
    process.env[ENV.DISABLE_KEYCHAIN] = "1";
    setGlobalAllowPlaintextFlag(false);
    resetGlobalMasterKeyRegistryForTests();
});

afterEach(() => {
    for (const dir of tmpDirs) rmSync(dir, { recursive: true, force: true });
    for (const [k, v] of [
        [ENV.CREDENTIAL_KEY, origEnv.key],
        [ENV.MASTER_PASSWORD, origEnv.pw],
        [ENV.ALLOW_PLAINTEXT, origEnv.allow],
        [ENV.DISABLE_KEYCHAIN, origEnv.disableKeychain],
        ["CI", origEnv.ci],
    ] as const) {
        if (v !== undefined) process.env[k] = v;
        else delete process.env[k];
    }
    setGlobalAllowPlaintextFlag(false);
    resetGlobalMasterKeyRegistryForTests();
    vi.restoreAllMocks();
});

// ---------------------------------------------------------------------------
// keychain の in-memory Fake
// ---------------------------------------------------------------------------

type Stored = Map<string, string>;

/** 複合キーの区切り。不可視文字を直書きせず明示エスケープで書く。 */
const KEY_SEP = "\u0000";

function fakeKeychain(store: Stored): KeychainStore {
    class FakeEntry {
        constructor(
            private readonly service: string,
            private readonly username: string,
        ) {}
        private get key(): string {
            return `${this.service}${KEY_SEP}${this.username}`;
        }
        getPassword(): string | null {
            return store.get(this.key) ?? null;
        }
        setPassword(password: string): void {
            store.set(this.key, password);
        }
        deletePassword(): boolean {
            return store.delete(this.key);
        }
    }
    return new KeychainStore(FakeEntry);
}

// ---------------------------------------------------------------------------
// ハーネス
// ---------------------------------------------------------------------------

type Harness = {
    configPath: string;
    credDir: string;
    writer: FileProfileWriter;
    store: CredentialStore;
    fileStore: FileStore;
    /** primary backend。破損 index の注入に使う。 */
    primary: FileStore | KeychainStore;
};

type Seed = {
    profiles: Record<string, ProfileEntry>;
    defaultProfile?: string;
};

function seedConfig(configPath: string, seed: Seed): void {
    const cfg: RootConfigInput = { profiles: seed.profiles };
    if (seed.defaultProfile !== undefined) {
        cfg.default_profile = seed.defaultProfile;
    }
    saveConfigToPath(configPath, cfg);
}

function makeTmp(): string {
    const dir = mkdtempSync(join(tmpdir(), "cli-pdel-"));
    tmpDirs.push(dir);
    return dir;
}

function setupHarness(backend: BackendName, seed: Seed): Harness {
    const tmp = makeTmp();
    const configPath = join(tmp, "config.yaml");
    const credDir = join(tmp, "credentials");
    seedConfig(configPath, seed);

    let keychain: KeychainStore | null = null;

    if (backend === "keychain") {
        // isAvailable() の早期 false を自スコープだけ解除する。
        delete process.env[ENV.DISABLE_KEYCHAIN];
        keychain = fakeKeychain(new Map<string, string>());
    } else if (backend === "file-encrypted") {
        process.env[ENV.CREDENTIAL_KEY] = Buffer.alloc(32)
            .fill(0x33)
            .toString("base64");
    } else {
        setGlobalAllowPlaintextFlag(true);
    }

    // 暗号化 file-store は writeWithPreflight が **グローバル** registry を
    // ensure するため、FileStore にも同じインスタンスを渡して二重管理を防ぐ。
    const fileStore = new FileStore(credDir, getGlobalMasterKeyRegistry());
    const store = new CredentialStore({ keychain, fileStore });

    return {
        configPath,
        credDir,
        writer: new FileProfileWriter(configPath),
        store,
        fileStore,
        primary: keychain ?? fileStore,
    };
}

function sampleBundle(): OAuthTokenBundle {
    return {
        accessToken: "access-token",
        refreshToken: "refresh-token",
        expiresAt: 1_900_000_000_000,
        clientId: "client-id",
        sessionId: null,
        scopes: [],
    };
}

async function writeApiKey(
    h: Harness,
    origin: string,
    profile: string,
    value: string,
): Promise<void> {
    await h.store.writeWithPreflight(origin, profile, "apikey", "", value, {
        printBanner: false,
    });
}

function silenceStderr(): void {
    vi.spyOn(console, "error").mockImplementation(() => {});
}

// ---------------------------------------------------------------------------
// 1 / 2 / 4 / 5: backend 横断
// ---------------------------------------------------------------------------

describe.each(BACKENDS)("profile:delete (%s)", (backend: BackendName) => {
    it("1. credential (apikey + OAuth token) が消える", async () => {
        const h = setupHarness(backend, {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
        await writeOAuthToken(h.store, ORIGIN_A, "prod", sampleBundle(), {
            printBanner: false,
        });
        expect(readOAuthToken(h.store, ORIGIN_A, "prod")).not.toBeNull();

        executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
        );

        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBeNull();
        // OAuth token は meta 名前空間 = clearProfile では消えない (keychain)。
        // deleteOAuthToken を明示的に呼ぶ設計であることを固定する。
        expect(readOAuthToken(h.store, ORIGIN_A, "prod")).toBeNull();
        expect(h.store.listItems(ORIGIN_A, "prod")).toEqual([]);

        if (backend !== "keychain") {
            expect(
                existsSync(
                    join(h.credDir, deriveProfileHash12(ORIGIN_A, "prod")),
                ),
            ).toBe(false);
        }
    });

    it("2. config エントリが消える", async () => {
        const h = setupHarness(backend, {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");

        executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
        );

        expect(h.writer.get("prod")).toBeUndefined();
        expect(h.writer.list().map((r) => r.name)).toEqual(["staging"]);
    });

    it("4. 他プロファイルの credential が生存する", async () => {
        const h = setupHarness(backend, {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
        await writeApiKey(h, ORIGIN_A, "staging", "staging-secret");

        executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
        );

        if (backend === "file-encrypted") {
            // 偽陽性の排除: MasterKeyRegistry のプロセス内キャッシュを捨て、
            // FileStore / CredentialStore を新インスタンスで組み直す
            // (別プロセス相当)。共有 master key が壊れていないことまで見る。
            resetGlobalMasterKeyRegistryForTests();
            const freshRegistry = new MasterKeyRegistry();
            const freshFileStore = new FileStore(h.credDir, freshRegistry);
            const freshStore = new CredentialStore({
                keychain: null,
                fileStore: freshFileStore,
            });
            await freshRegistry.ensure(ORIGIN_A, "staging", {
                env: process.env,
                isTty: false,
            });
            expect(
                freshStore.read(ORIGIN_A, "staging", "apikey", ""),
            ).toBe("staging-secret");
            expect(freshStore.listItems(ORIGIN_A, "staging")).toEqual([
                { kind: "apikey", id: "" },
            ]);
        } else {
            expect(h.store.read(ORIGIN_A, "staging", "apikey", "")).toBe(
                "staging-secret",
            );
            expect(h.store.listItems(ORIGIN_A, "staging")).toEqual([
                { kind: "apikey", id: "" },
            ]);
        }
    });

    it("5. credential が無いプロファイルの削除も成功する (冪等)", () => {
        const h = setupHarness(backend, {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });

        expect(() =>
            executeProfileDeletion(
                { writer: h.writer, store: h.store },
                planProfileDeletion(h.writer, "prod", { clearDefault: true }),
            ),
        ).not.toThrow();
        expect(h.writer.get("prod")).toBeUndefined();
    });
});

// ---------------------------------------------------------------------------
// 3. default_profile の 5 ケース (判断 8)
// ---------------------------------------------------------------------------

describe("default_profile transitions", () => {
    it("対象が default / clearDefault:false → 計画段階で conflict、副作用ゼロ", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
        const before = readFileSync(h.configPath, "utf-8");

        let thrown: unknown = null;
        try {
            planProfileDeletion(h.writer, "prod", { clearDefault: false });
        } catch (e) {
            thrown = e;
        }
        expect(thrown).toBeInstanceOf(ProfileResolutionError);
        expect((thrown as ProfileResolutionError).exitCode).toBe(
            ExitCode.ProfileConflict,
        );
        // config も credential も変わらない (計画フェーズは副作用ゼロ)。
        expect(readFileSync(h.configPath, "utf-8")).toBe(before);
        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe(
            "prod-secret",
        );
    });

    it("対象が default / clearDefault:true / 残 1 件 → default が付け替わる", () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        const result = executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
        );
        expect(result.nextDefault).toBe("staging");
        expect(h.writer.readState().defaultProfile).toBe("staging");
    });

    it("対象が default / clearDefault:true / 残 0 件 → default が消える", () => {
        const h = setupHarness("file-plaintext", {
            profiles: { prod: { api_url: API_URL_A } },
            defaultProfile: "prod",
        });
        const result = executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
        );
        expect(result.nextDefault).toBeNull();
        expect(result.remaining).toEqual([]);
        expect(h.writer.readState().defaultProfile).toBeUndefined();
    });

    it("対象が default / clearDefault:true / 残 2 件以上 → 勝手に選ばない", () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
                dev: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        const result = executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
        );
        expect(result.nextDefault).toBeNull();
        expect(result.remaining).toHaveLength(2);
        expect(h.writer.readState().defaultProfile).toBeUndefined();
    });

    it("対象が default でない → default は変わらない", () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "staging", { clearDefault: false }),
        );
        expect(h.writer.readState().defaultProfile).toBe("prod");
    });
});

// ---------------------------------------------------------------------------
// 5b. 壊れた profile / 破損 index の分岐
// ---------------------------------------------------------------------------

describe("壊れた profile / 破損 index", () => {
    it("b. api_url が非 http(s) スキームなら credential に触れず config だけ消える", () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                broken: { api_url: "ftp://x.example.com" },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "staging",
        });
        silenceStderr();
        const result = executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "broken", { clearDefault: false }),
        );
        expect(result.credentialsSkipped).toBe(true);
        expect(h.writer.get("broken")).toBeUndefined();
    });

    it("c. api_url 未設定なら credential に触れず config だけ消える", () => {
        const h = setupHarness("file-plaintext", {
            profiles: { broken: {}, staging: { api_url: API_URL_A } },
            defaultProfile: "staging",
        });
        silenceStderr();
        const result = executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "broken", { clearDefault: false }),
        );
        expect(result.credentialsSkipped).toBe(true);
        expect(h.writer.get("broken")).toBeUndefined();
    });

    it("d. file backend の index 破損では削除が完遂する", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
        // credential index を不正 JSON で上書きする。
        h.fileStore.write(ORIGIN_A, "prod", "meta", "index", "{not json");
        silenceStderr();

        const result = executeProfileDeletion(
            { writer: h.writer, store: h.store },
            planProfileDeletion(h.writer, "prod", { clearDefault: true }),
        );

        expect(result.credentialIndexCorrupted).toBe(true);
        expect(h.writer.get("prod")).toBeUndefined();
        expect(
            existsSync(join(h.credDir, deriveProfileHash12(ORIGIN_A, "prod"))),
        ).toBe(false);
    });

    it("e. keychain backend の index 破損では config を残して exit 18 で止まる", async () => {
        const h = setupHarness("keychain", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
        await writeApiKey(h, ORIGIN_A, "staging", "staging-secret");
        h.primary.write(ORIGIN_A, "prod", "meta", "index", "{not json");
        silenceStderr();

        let thrown: unknown = null;
        try {
            executeProfileDeletion(
                { writer: h.writer, store: h.store },
                planProfileDeletion(h.writer, "prod", { clearDefault: true }),
            );
        } catch (e) {
            thrown = e;
        }
        expect(thrown).toBeInstanceOf(CredentialStoreError);
        expect((thrown as CredentialStoreError).exitCode).toBe(
            ExitCode.CredentialStoreFailure,
        );
        // config を消すと api_url を失い、keychain の秘密が到達不能になる。
        expect(h.writer.get("prod")).toBeDefined();
        // 他プロファイルの credential は無傷。
        expect(h.store.read(ORIGIN_A, "staging", "apikey", "")).toBe(
            "staging-secret",
        );
    });

    it("f. index 破損以外の CredentialStoreError は握り潰さない", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");

        /** kind 既定 ("unknown") の CredentialStoreError を投げるスタブ。 */
        class UnknownFailureStore extends CredentialStore {
            override clearProfile(): void {
                throw new CredentialStoreError(
                    "backend exploded",
                    ExitCode.CredentialStoreFailure,
                );
            }
        }
        const store = new UnknownFailureStore({
            keychain: null,
            fileStore: h.fileStore,
        });
        silenceStderr();

        expect(() =>
            executeProfileDeletion(
                { writer: h.writer, store },
                planProfileDeletion(h.writer, "prod", { clearDefault: true }),
            ),
        ).toThrow("backend exploded");
        // config は残る (credential を落とし切れていないため)。
        expect(h.writer.get("prod")).toBeDefined();
    });
});

// ---------------------------------------------------------------------------
// 5c. 確認待ち中の config 変更 (TOCTOU)
// ---------------------------------------------------------------------------

describe("確認待ち中の config 変更 (TOCTOU ガード)", () => {
    it("a. api_url が A→B に変わったら何も削除せず競合終了する", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: { prod: { api_url: API_URL_A } },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
        await writeApiKey(h, ORIGIN_B, "prod", "secret-b");

        const plan = planProfileDeletion(h.writer, "prod", {
            clearDefault: true,
        });

        // 別プロセス相当の設定更新。applyAtomic の patch 型では api_url を
        // 変更できないため、config を直接書き戻して「他プロセスが書き替えた」
        // 状態を作る。
        seedConfig(h.configPath, {
            profiles: { prod: { api_url: API_URL_B } },
            defaultProfile: "prod",
        });

        expect(() =>
            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
        ).toThrow(ProfileResolutionError);
        // credential も config も無傷。
        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
        expect(h.store.read(ORIGIN_B, "prod", "apikey", "")).toBe("secret-b");
        expect(h.writer.get("prod")).toBeDefined();
    });

    it("b. default_profile が付け替わったら競合終了する", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
        const plan = planProfileDeletion(h.writer, "prod", {
            clearDefault: true,
        });
        h.writer.useDefaultProfile("staging");

        expect(() =>
            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
        ).toThrow(ProfileResolutionError);
        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
        expect(h.writer.get("prod")).toBeDefined();
    });

    it("c. 別プロファイルが追加されたら競合終了する", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
        const plan = planProfileDeletion(h.writer, "prod", {
            clearDefault: true,
        });
        h.writer.addProfile("dev", { api_url: API_URL_A });

        expect(() =>
            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
        ).toThrow(ProfileResolutionError);
        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
        expect(h.writer.get("prod")).toBeDefined();
    });

    it("d. 対象プロファイル自体が消えていたら not-found で止まる", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
        const plan = planProfileDeletion(h.writer, "prod", {
            clearDefault: true,
        });
        h.writer.deleteProfile("prod", { clearDefault: true });

        let thrown: unknown = null;
        try {
            executeProfileDeletion({ writer: h.writer, store: h.store }, plan);
        } catch (e) {
            thrown = e;
        }
        expect(thrown).toBeInstanceOf(ProfileResolutionError);
        expect((thrown as ProfileResolutionError).exitCode).toBe(
            ExitCode.ProfileNotFound,
        );
        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBe("secret-a");
    });

    it("e. 何も変わっていなければ正常に削除される (誤検知しない)", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "secret-a");
        const plan = planProfileDeletion(h.writer, "prod", {
            clearDefault: true,
        });

        expect(() =>
            executeProfileDeletion({ writer: h.writer, store: h.store }, plan),
        ).not.toThrow();
        expect(h.writer.get("prod")).toBeUndefined();
    });
});

// ---------------------------------------------------------------------------
// 6. ProfileWriter.deleteProfile の原子性
// ---------------------------------------------------------------------------

describe("deleteProfile の原子性", () => {
    function writerWith(seed: Seed): { writer: FileProfileWriter; path: string } {
        const tmp = makeTmp();
        const path = join(tmp, "config.yaml");
        seedConfig(path, seed);
        return { writer: new FileProfileWriter(path), path };
    }

    const twoProfiles: Seed = {
        profiles: {
            prod: { api_url: API_URL_A },
            staging: { api_url: API_URL_A },
        },
        defaultProfile: "prod",
    };

    it("1 回の呼び出しで profiles 除去と default 付け替えが同時に反映される", () => {
        const { writer, path } = writerWith(twoProfiles);
        writer.deleteProfile("prod", {
            clearDefault: true,
            nextDefault: "staging",
        });
        const reloaded = new FileProfileWriter(path);
        expect(reloaded.get("prod")).toBeUndefined();
        expect(reloaded.readState().defaultProfile).toBe("staging");
    });

    it.each([
        [
            "対象が default でない",
            "staging",
            { clearDefault: true, nextDefault: "prod" },
        ],
        ["clearDefault 無し", "prod", { nextDefault: "staging" }],
        [
            "nextDefault が自分自身",
            "prod",
            { clearDefault: true, nextDefault: "prod" },
        ],
        [
            "nextDefault が不在",
            "prod",
            { clearDefault: true, nextDefault: "ghost" },
        ],
    ] as const)(
        "不正な組合せ (%s) では config が一切変わらない",
        (_label, target, opts) => {
            const { writer, path } = writerWith(twoProfiles);
            const before = readFileSync(path, "utf-8");
            expect(() => writer.deleteProfile(target, opts)).toThrow();
            expect(readFileSync(path, "utf-8")).toBe(before);
        },
    );
});

// ---------------------------------------------------------------------------
// 7. 部分失敗の収束
// ---------------------------------------------------------------------------

describe("部分失敗の収束", () => {
    it("credential 破棄後に config 保存が失敗しても再実行で収束する", async () => {
        const h = setupHarness("file-plaintext", {
            profiles: {
                prod: { api_url: API_URL_A },
                staging: { api_url: API_URL_A },
            },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");

        const real = h.writer;
        let failNext = true;
        const flaky: ProfileWriter = {
            list: () => real.list(),
            get: (name) => real.get(name),
            readState: () => real.readState(),
            snapshot: (name) => real.snapshot(name),
            addProfile: (name, init) => {
                real.addProfile(name, init);
            },
            updateExpectedEnv: (name, expected) => {
                real.updateExpectedEnv(name, expected);
            },
            deleteProfile: (name, opts) => {
                if (failNext) {
                    failNext = false;
                    throw new Error("disk full");
                }
                real.deleteProfile(name, opts);
            },
            useDefaultProfile: (name) => {
                real.useDefaultProfile(name);
            },
            applyAtomic: (name, patch, verifyResult) => {
                real.applyAtomic(name, patch, verifyResult);
            },
            persistVerificationMeta: (name, meta) => {
                real.persistVerificationMeta(name, meta);
            },
        };

        const errors: string[] = [];
        vi.spyOn(console, "error").mockImplementation((msg: unknown) => {
            errors.push(String(msg));
        });

        // (a) 1 回目: throw する
        expect(() =>
            executeProfileDeletion(
                { writer: flaky, store: h.store },
                planProfileDeletion(flaky, "prod", { clearDefault: true }),
            ),
        ).toThrow("disk full");

        // (b) 再実行コマンド文字列が stderr に出る
        expect(errors.join("\n")).toContain(`${BIN_NAME} profile:delete prod`);
        // (c) config 側には profile が残る (状態が観測可能)
        expect(real.get("prod")).toBeDefined();
        // credential は落ちている
        expect(h.store.read(ORIGIN_A, "prod", "apikey", "")).toBeNull();

        // (d) 同じコマンドの再実行で収束する (credential 不在パスを通って成功)
        expect(() =>
            executeProfileDeletion(
                { writer: flaky, store: h.store },
                planProfileDeletion(flaky, "prod", { clearDefault: true }),
            ),
        ).not.toThrow();
        expect(real.get("prod")).toBeUndefined();
    });
});

// ---------------------------------------------------------------------------
// 事前検証 (計画フェーズ) の失敗モード
// ---------------------------------------------------------------------------

describe("planProfileDeletion の事前検証", () => {
    it("未登録プロファイルは not-found (exit 11)", () => {
        const h = setupHarness("file-plaintext", {
            profiles: { prod: { api_url: API_URL_A } },
            defaultProfile: "prod",
        });
        let thrown: unknown = null;
        try {
            planProfileDeletion(h.writer, "ghost", { clearDefault: true });
        } catch (e) {
            thrown = e;
        }
        expect(thrown).toBeInstanceOf(ProfileResolutionError);
        expect((thrown as ProfileResolutionError).exitCode).toBe(
            ExitCode.ProfileNotFound,
        );
    });

    it("計画フェーズは stderr に何も書かない (副作用ゼロ)", () => {
        const h = setupHarness("file-plaintext", {
            profiles: { broken: {}, staging: { api_url: API_URL_A } },
            defaultProfile: "staging",
        });
        const spy = vi.spyOn(console, "error").mockImplementation(() => {});
        const plan = planProfileDeletion(h.writer, "broken", {
            clearDefault: false,
        });
        expect(spy).not.toHaveBeenCalled();
        expect(plan.credentials.kind).toBe("unlocatable");
    });
});

// ---------------------------------------------------------------------------
// OAuth token 破棄が clearProfile 任せでないことの直接固定
// ---------------------------------------------------------------------------

describe("OAuth token の破棄経路", () => {
    it("keychain backend では clearProfile だけでは OAuth token が残る", async () => {
        const h = setupHarness("keychain", {
            profiles: { prod: { api_url: API_URL_A } },
            defaultProfile: "prod",
        });
        await writeApiKey(h, ORIGIN_A, "prod", "prod-secret");
        await writeOAuthToken(h.store, ORIGIN_A, "prod", sampleBundle(), {
            printBanner: false,
        });

        // clearProfile は index 掲載アイテムと meta:index しか消さない。
        h.store.clearProfile(ORIGIN_A, "prod");
        expect(readOAuthToken(h.store, ORIGIN_A, "prod")).not.toBeNull();

        // deleteOAuthToken を明示的に呼ぶと消える = 削除経路が必要とする理由。
        deleteOAuthToken(h.store, ORIGIN_A, "prod");
        expect(readOAuthToken(h.store, ORIGIN_A, "prod")).toBeNull();
    });
});
