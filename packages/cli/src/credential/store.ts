import { z } from "zod";
import type { BackendStore } from "./backend.js";
import { printBackendOnce } from "./backend-detect.js";
import { CredentialStoreError } from "./errors.js";
import { FileStore, shouldUseEncryptedPath } from "./file-store.js";
import type { ItemKind } from "./key-derivation.js";
import { KeychainStore } from "./keychain.js";
import { ensureMasterKey } from "./master-key-registry.js";
import {
    ENV_CREDENTIAL_KEY,
    ENV_MASTER_PASSWORD,
} from "./master-password.js";
import { isPlaintextOptInAllowed } from "../runtime-state.js";

export type StoreBackend =
    | "keychain"
    | "file-encrypted"
    | "file-plaintext"
    | "unavailable";

export type IndexEntry = {
    kind: Exclude<ItemKind, "meta">;
    id: string;
};

const IndexSchema = z.array(
    z
        .object({
            kind: z.enum(["apikey", "credential"]),
            id: z.string(),
        })
        .strict(),
);

type IndexItem = z.infer<typeof IndexSchema>[number];

const META_INDEX_ID = "index";

export type PurgeProfileResult = {
    /**
     * このプロファイルの資格情報を **取りこぼしなく** 破棄できたか。
     * `false` のとき、呼び出し側は config を消してはならない
     * (api_url を失うと残った資格情報の在り処を二度と導出できなくなる)。
     */
    complete: boolean;
    /** credential index が読めなかったか (診断用)。 */
    indexCorrupted: boolean;
};

function assertMetaIdSafe(metaId: string): void {
    if (metaId === META_INDEX_ID) {
        throw new Error(
            `Internal error: meta id "${META_INDEX_ID}" is reserved for the `
                + "credential index. Use a namespaced id (e.g. `migration:…`).",
        );
    }
    if (metaId.length === 0) {
        throw new Error("Internal error: meta id must not be empty.");
    }
}

export class CredentialStore {
    private readonly keychain: KeychainStore | null;
    private readonly fileStore: FileStore;

    constructor(
        opts: {
            keychain?: KeychainStore | null;
            fileStore?: FileStore;
        } = {},
    ) {
        const candidate =
            opts.keychain === undefined ? new KeychainStore() : opts.keychain;
        this.keychain =
            candidate !== null && candidate.isAvailable() ? candidate : null;
        this.fileStore = opts.fileStore ?? new FileStore();
    }

    backend(): StoreBackend {
        if (this.keychain !== null) return "keychain";
        const rawKey = process.env[ENV_CREDENTIAL_KEY];
        const password = process.env[ENV_MASTER_PASSWORD];
        if (
            (rawKey !== undefined && rawKey !== "")
            || (password !== undefined && password !== "")
        ) {
            return "file-encrypted";
        }
        if (isPlaintextOptInAllowed()) {
            return "file-plaintext";
        }
        return "unavailable";
    }

    write(
        canonicalOrigin: string,
        profileName: string,
        itemKind: Exclude<ItemKind, "meta">,
        itemId: string,
        value: string,
    ): void {
        this.primary().write(
            canonicalOrigin,
            profileName,
            itemKind,
            itemId,
            value,
        );
        this.upsertIndex(canonicalOrigin, profileName, {
            kind: itemKind,
            id: itemId,
        });
    }

    /**
     * Read/write common single entry point that fixes the ordering
     * **lazy backend warning → master-key preflight** for every credential
     * access (F-0-05). Both the read facade ({@link prepareForAccess} callers)
     * and the write preflight ({@link prepareForWrite}) funnel through here so
     * the warning is emitted exactly when a credential is first actually used
     * and never on read-only / local-only paths.
     *
     * The preflight loads the master key when (and only when) the encrypted
     * file-store path will be used, so the subsequent synchronous
     * `read`/`write`/`writeMeta` does not exit(32) on an unloaded key. No-op
     * for the keychain backend (file-store unused) and for an explicit
     * plaintext opt-in (no master key required). The encryption-path decision
     * is shared with `FileStore.write` via {@link shouldUseEncryptedPath} so
     * the two can never drift.
     */
    async prepareForAccess(
        canonicalOrigin: string,
        profileName: string,
        opts: { printBanner?: boolean } = {},
    ): Promise<void> {
        printBackendOnce(this, opts.printBanner !== false);
        if (this.keychain !== null) return;
        if (!shouldUseEncryptedPath()) return;
        await ensureMasterKey(canonicalOrigin, profileName);
    }

    /**
     * Write-side preflight (F-0-02). Delegates to {@link prepareForAccess} so
     * the warning + master-key ordering is identical for reads and writes; the
     * `printBanner` option is propagated so write-only `--json` / `--quiet`
     * paths stay quiet.
     */
    async prepareForWrite(
        canonicalOrigin: string,
        profileName: string,
        opts: { printBanner?: boolean } = {},
    ): Promise<void> {
        await this.prepareForAccess(canonicalOrigin, profileName, opts);
    }

    /**
     * Preflight-bundled credential write (F-0-02). All credential **value**
     * writes from commands/helpers go through this so the master-key preflight
     * cannot be forgotten. The bare {@link write} stays for index-management
     * internals only.
     */
    async writeWithPreflight(
        canonicalOrigin: string,
        profileName: string,
        itemKind: Exclude<ItemKind, "meta">,
        itemId: string,
        value: string,
        opts: { printBanner?: boolean } = {},
    ): Promise<void> {
        await this.prepareForWrite(canonicalOrigin, profileName, opts);
        this.write(canonicalOrigin, profileName, itemKind, itemId, value);
    }

    /**
     * Preflight-bundled meta write (F-0-02). Used by the OAuth token-bundle
     * write path.
     */
    async writeMetaWithPreflight(
        canonicalOrigin: string,
        profileName: string,
        metaId: string,
        value: string,
        opts: { printBanner?: boolean } = {},
    ): Promise<void> {
        await this.prepareForWrite(canonicalOrigin, profileName, opts);
        this.writeMeta(canonicalOrigin, profileName, metaId, value);
    }

    read(
        canonicalOrigin: string,
        profileName: string,
        itemKind: Exclude<ItemKind, "meta">,
        itemId: string,
    ): string | null {
        return this.primary().read(
            canonicalOrigin,
            profileName,
            itemKind,
            itemId,
        );
    }

    has(
        canonicalOrigin: string,
        profileName: string,
        itemKind: Exclude<ItemKind, "meta">,
        itemId: string,
    ): boolean {
        const idx = this.readIndex(canonicalOrigin, profileName);
        return idx.some((e) => e.kind === itemKind && e.id === itemId);
    }

    delete(
        canonicalOrigin: string,
        profileName: string,
        itemKind: Exclude<ItemKind, "meta">,
        itemId: string,
    ): void {
        this.primary().delete(
            canonicalOrigin,
            profileName,
            itemKind,
            itemId,
        );
        this.removeFromIndex(canonicalOrigin, profileName, {
            kind: itemKind,
            id: itemId,
        });
    }

    listItems(
        canonicalOrigin: string,
        profileName: string,
    ): ReadonlyArray<IndexEntry> {
        return this.readIndex(canonicalOrigin, profileName);
    }

    clearProfile(canonicalOrigin: string, profileName: string): void {
        const items = this.readIndex(canonicalOrigin, profileName);
        const backend = this.primary();
        for (const it of items) {
            backend.delete(canonicalOrigin, profileName, it.kind, it.id);
        }
        backend.delete(canonicalOrigin, profileName, "meta", META_INDEX_ID);
        this.fileStore.clearProfile(canonicalOrigin, profileName);
    }

    /**
     * Low-level meta entry API (U-22). Index is not touched — internal meta
     * keys like `migration:{siteId}` live alongside `index` without being
     * enumerated by `listItems`. Callers are responsible for key namespacing
     * (we reject `"index"` to protect the credential index from clobbering).
     */
    readMeta(
        canonicalOrigin: string,
        profileName: string,
        metaId: string,
    ): string | null {
        assertMetaIdSafe(metaId);
        return this.primary().read(
            canonicalOrigin,
            profileName,
            "meta",
            metaId,
        );
    }

    writeMeta(
        canonicalOrigin: string,
        profileName: string,
        metaId: string,
        value: string,
    ): void {
        assertMetaIdSafe(metaId);
        this.primary().write(
            canonicalOrigin,
            profileName,
            "meta",
            metaId,
            value,
        );
    }

    deleteMeta(
        canonicalOrigin: string,
        profileName: string,
        metaId: string,
    ): void {
        assertMetaIdSafe(metaId);
        this.primary().delete(
            canonicalOrigin,
            profileName,
            "meta",
            metaId,
        );
    }

    /**
     * `profile:delete` 用の破棄 API。
     *
     * `clearProfile` は index を読めないと
     * `CredentialStoreError`(kind: "corrupted-index") を投げる。
     * その場合の挙動は backend で本質的に異なる:
     *
     *   - file backend: プロファイルディレクトリを丸ごと落とせるので、
     *     index が読めなくても **取りこぼしは発生しない** → complete: true
     *   - keychain backend: 列挙手段が無く、index を失うと個々の item を
     *     特定できない → **complete: false**。呼び出し側は config を残し、
     *     利用者に手動清掃を求めること (config を消すと api_url が失われ、
     *     残った資格情報が永久に到達不能な孤児になる)
     */
    purgeProfile(
        canonicalOrigin: string,
        profileName: string,
    ): PurgeProfileResult {
        try {
            this.clearProfile(canonicalOrigin, profileName);
            return { complete: true, indexCorrupted: false };
        } catch (e) {
            // index 破損**だけ**を握る。復号失敗・削除失敗など他の
            // CredentialStoreError は握り潰さず素通しする。
            if (
                !(e instanceof CredentialStoreError)
                || e.kind !== "corrupted-index"
            ) {
                throw e;
            }
        }
        // index が読めなくても消せるものは消す。
        this.primary().delete(
            canonicalOrigin,
            profileName,
            "meta",
            META_INDEX_ID,
        );
        this.fileStore.clearProfile(canonicalOrigin, profileName);
        // keychain が primary のときだけ取りこぼしがありうる。判定は
        // `primary()` と **同じ式**から導く (「keychain フィールドの有無」と
        // 「実際に使われる backend」が将来ずれても嘘をつかないため)。
        return {
            complete: this.primary() === this.fileStore,
            indexCorrupted: true,
        };
    }

    /**
     * Expose the underlying FileStore for low-level enumeration. Returns
     * null when the keychain backend is active — keychain does not expose
     * enumeration. Used by tests to exercise corruption paths.
     */
    fileStoreOrNull(): FileStore | null {
        return this.keychain === null ? this.fileStore : null;
    }

    private readIndex(
        canonicalOrigin: string,
        profileName: string,
    ): IndexItem[] {
        const raw = this.primary().read(
            canonicalOrigin,
            profileName,
            "meta",
            META_INDEX_ID,
        );
        if (!raw) return [];
        try {
            const parsed = IndexSchema.parse(JSON.parse(raw));
            return [...parsed];
        } catch (e) {
            // Core layer (C2 / T144): surface the problem as a typed
            // error so the command-action layer can render stderr and
            // map the exit code. The store must not poke
            // `process.exit` directly any more.
            throw CredentialStoreError.corruptedIndex(
                profileName,
                (e as Error).message,
            );
        }
    }

    private upsertIndex(
        canonicalOrigin: string,
        profileName: string,
        entry: IndexItem,
    ): void {
        const curr = this.readIndex(canonicalOrigin, profileName);
        const next = curr.filter(
            (e) => !(e.kind === entry.kind && e.id === entry.id),
        );
        next.push(entry);
        this.primary().write(
            canonicalOrigin,
            profileName,
            "meta",
            META_INDEX_ID,
            JSON.stringify(next),
        );
    }

    private removeFromIndex(
        canonicalOrigin: string,
        profileName: string,
        entry: IndexItem,
    ): void {
        const curr = this.readIndex(canonicalOrigin, profileName);
        const next = curr.filter(
            (e) => !(e.kind === entry.kind && e.id === entry.id),
        );
        if (next.length === 0) {
            this.primary().delete(
                canonicalOrigin,
                profileName,
                "meta",
                META_INDEX_ID,
            );
            return;
        }
        this.primary().write(
            canonicalOrigin,
            profileName,
            "meta",
            META_INDEX_ID,
            JSON.stringify(next),
        );
    }

    private primary(): BackendStore {
        return this.keychain ?? this.fileStore;
    }
}
