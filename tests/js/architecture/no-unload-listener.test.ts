import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * 認証済み画面に `unload` / `beforeunload` リスナを持ち込まないことを
 * deny-by-default で固定する。
 *
 * **これは debug 設備の都合ではない。** `unload` / `beforeunload` が入ると
 * 認証済み画面が bfcache の**対象外になる、または適格性が不安定になる**ため、
 * bfcache-guard.ts が守っている経路 B (Safari の真の bfcache 復元。
 * docs/supported-browsers.md が正本) が無効化されうる。
 * 認証済み画面全体の bfcache 契約に関わる制約である。
 *
 * ブラウザ横断で「beforeunload があれば必ず bfcache 対象外」と断定はしない。
 * 禁止の理由は「対象外になる、または適格性を不安定にする」で十分である。
 *
 * さらに悪いことに、この破綻は **T085 の実機確認を無言で空振りにする**。
 * 空振りは「PII が出ない」に見えるため緑と誤認されうる
 * (まさに検証ページが潰そうとしている失敗モードそのもの)。
 *
 * 既知の限界: 検出は **文字列リテラル `"unload"` / `"beforeunload"`** に限定される。
 * 動的にイベント名を組み立てる書き方 (`addEventListener("before" + "unload")` 等) は
 * 検出外である。その種の書き方を導入する際は本テストのパターンも同時に更新すること
 * (tests/js/architecture/logout-call-site-inventory.test.ts が同様の限界を明記している前例に倣う)。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/**
 * 監視対象。検証ページ本体だけでは足りない —
 * AppLayout に beforeunload が入れば、検証ページ側をいくら縛っても検証条件が壊れる。
 */
const WATCHED_PATHS: readonly string[] = [
    // 経路 B の当事者
    "lib/bfcache-guard.ts",
    "app.ts",
    // 認証済み画面の共通レイアウト (ここに入ると全認証済み画面が影響を受ける)
    "components/templates/AppLayout.svelte",
    // T085 の検証設備
    "lib/debug",
    "pages/Debug",
] as const;

const FORBIDDEN_PATTERN = /["'`](?:before)?unload["'`]/;

const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;

/** 監視対象パス (ファイル or ディレクトリ) を実ファイル一覧へ展開する。 */
const resolveWatchedFiles = async (): Promise<string[]> => {
    const files: string[] = [];

    for (const watched of WATCHED_PATHS) {
        const absolute = path.join(JS_ROOT, watched);
        const stat = await fs.stat(absolute);

        if (stat.isFile()) {
            files.push(absolute);
            continue;
        }

        const entries = await fs.readdir(absolute, {
            recursive: true,
            withFileTypes: true,
        });
        for (const entry of entries) {
            if (!entry.isFile()) continue;
            if (!SOURCE_EXTENSIONS.includes(path.extname(entry.name))) continue;
            const parent =
                (entry as unknown as { parentPath?: string }).parentPath ??
                absolute;
            files.push(path.join(parent, entry.name));
        }
    }

    return files;
};

describe("no unload listener", () => {
    it("監視対象がすべて実在する (パス変更で検査が無言で空になるのを防ぐ)", async () => {
        const files = await resolveWatchedFiles();

        expect(files.length).toBeGreaterThan(0);
        for (const watched of WATCHED_PATHS) {
            await expect(fs.stat(path.join(JS_ROOT, watched))).resolves.toBeDefined();
        }
    });

    it("unload / beforeunload の文字列リテラルを含まない", async () => {
        const files = await resolveWatchedFiles();
        const offenders: string[] = [];

        for (const file of files) {
            const source = await fs.readFile(file, "utf8");
            if (FORBIDDEN_PATTERN.test(source)) {
                offenders.push(path.relative(JS_ROOT, file));
            }
        }

        expect(
            offenders,
            [
                "認証済み画面に unload / beforeunload リスナを追加しないこと。",
                "bfcache の適格性を壊し、経路 B (Safari の真の bfcache 復元) の保証と",
                "T085 の実機受入確認を無言で空振りにする。",
                `検出: ${offenders.join(", ")}`,
            ].join("\n"),
        ).toEqual([]);
    });
});
