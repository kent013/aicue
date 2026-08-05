import { describe, expect, it } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

/*
 * `@/lib/passkeys` の import 元を allowlist で固定する (deny-by-default)。
 *
 * 理由: WebAuthn ceremony は「options 取得 → 認証器操作 → 送信」の 3 段で、
 * 送信先とレスポンス契約 (Inertia か fetch か / 302 か 204 か) が operation ごとに違う
 * (詳細設計 施策 4-d の transport 契約)。呼び出し元が無秩序に増えると
 * 契約の食い違いが**無言失敗**として現れる (router が応答を解釈できない)。
 *
 * 増やすときは transport 契約の該当行と併せて判断すること。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const RESOURCES_JS = path.resolve(HERE, "../../../resources/js");

/** `@/lib/passkeys` を import してよいファイル (resources/js からの相対パス) */
const ALLOWED_IMPORTERS: ReadonlySet<string> = new Set([
    // パスキーの登録 / 削除 (Inertia transport)
    "components/features/auth/PasskeySection.svelte",
    // step-up 再認証 (fetch + 204 transport)
    "components/organisms/RecentAuthModal.svelte",
    // guest のパスキーログイン (fetch + {redirect} transport)
    "pages/Auth/Login.svelte",
    // passkeys prop の型 (PasskeyListItem) を PasskeySection へ渡す page
    "pages/Settings/Security.svelte",
    // 全画面の step-up confirm 画面 (Inertia transport。サーバの intended へ戻す)
    "pages/Auth/ConfirmRecentAuth.svelte",
]);

const TARGET_EXTENSIONS: ReadonlySet<string> = new Set([".ts", ".svelte"]);

const listFiles = async (dir: string): Promise<string[]> => {
    const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
    const files: string[] = [];
    for (const entry of entries) {
        if (!entry.isFile()) continue;
        if (!TARGET_EXTENSIONS.has(path.extname(entry.name))) continue;
        const parent = (entry as unknown as { parentPath?: string }).parentPath ?? dir;
        files.push(path.join(parent, entry.name));
    }
    return files;
};

const IMPORT_PATTERN = /from\s+["'](@\/lib\/passkeys)["']|import\s+["'](@\/lib\/passkeys)["']/;

describe("passkeys import isolation", () => {
    it("@/lib/passkeys の import 元は allowlist のみ", async () => {
        const files = await listFiles(RESOURCES_JS);
        const importers: string[] = [];

        for (const file of files) {
            const relative = path.relative(RESOURCES_JS, file).split(path.sep).join("/");
            if (relative === "lib/passkeys.ts") continue;
            const content = await fs.readFile(file, "utf-8");
            if (IMPORT_PATTERN.test(content)) {
                importers.push(relative);
            }
        }

        const unexpected = importers.filter((file) => !ALLOWED_IMPORTERS.has(file));
        expect(unexpected).toEqual([]);

        // 走査が空振りしていない (allowlist の全員が実際に import している)
        expect(importers.length).toBeGreaterThan(0);
        for (const allowed of ALLOWED_IMPORTERS) {
            expect(importers).toContain(allowed);
        }
    });
});
