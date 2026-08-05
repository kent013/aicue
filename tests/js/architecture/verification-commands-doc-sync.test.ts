/**
 * 検証コマンド列の同期ゲート — package.json の「検証系 script」が
 * AGENTS.md と .claude/skills/app-implement/SKILL.md の検証コマンド列に載っていることを
 * deny-by-default で強制する。
 *
 * なぜ必要か: T104 で CI の frontend job が `pnpm build:packages` を回すようになったが、
 * AGENTS.md への追記が漏れ、app-implement/SKILL.md に至っては packages 系 3 本が
 * 1 つも無い状態が続いた (監査サイクル 2 の docs-freshness §2-3)。手順どおり実装した
 * worktree は packages のビルド破壊をローカルで検出できず、CI で初めて赤くなる。
 *
 * 免除は「理由付き」でしか書けない (EXEMPT)。免除エントリが package.json から消えたら
 * 逆方向検査 (V3) が落ちる = stale 免除の残置を許さない
 * (tests/Architecture/ScriptsReadmeInventoryTest.php と同じ作法)。
 *
 * 照合範囲は VERIFICATION_COMMANDS:BEGIN 〜 END の内側のみ。文書全体を検索すると
 * 別文脈で `pnpm build` に言及しただけで green になり形骸化する
 * (RouteBindingCustomBinderDocSyncTest と同じ方式)。
 */
import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const MARKER_BEGIN = "<!-- VERIFICATION_COMMANDS:BEGIN -->";
const MARKER_END = "<!-- VERIFICATION_COMMANDS:END -->";

/** 照合対象ファイル (どちらにも同じ script 集合が載っている必要がある)。 */
const TARGETS = ["AGENTS.md", ".claude/skills/app-implement/SKILL.md"] as const;

/**
 * 検証コマンド列に載せなくてよい script と、その理由。
 *
 * 理由を書けないものをここに足さないこと。package.json から消えた key を残置すると
 * V3 が落ちる。
 */
const EXEMPT: Record<string, string> = {
    dev: "開発サーバ起動。検証コマンドではない",
    "lint:fix": "lint の自動修正。検証は lint 側が担う",
    "test:ui": "vitest UI の対話起動。CI/検証で回すものではない",
    "test:watch": "watch 実行。単発検証ではない",
    "test:coverage": "カバレッジ計測。検証ゲートではない (test が正本)",
    "audit:gate": "supply-chain gate は CI/nightly の blocking 実行が正本 (AGENTS.md §依存脆弱性に別記)",
};

function repoFile(relative: string): string {
    return readFileSync(resolve(process.cwd(), relative), "utf-8");
}

/** package.json の scripts を読む (純関数の入力を作る境界)。 */
export function packageScriptNames(packageJson: string): string[] {
    const parsed = JSON.parse(packageJson) as { scripts?: Record<string, string> };
    return Object.keys(parsed.scripts ?? {});
}

/**
 * マーカー間の本文を返す (純関数)。マーカーがちょうど 1 組でなければ `null`。
 */
export function verificationCommandsSection(markdown: string): string | null {
    const begins = markdown.split(MARKER_BEGIN).length - 1;
    const ends = markdown.split(MARKER_END).length - 1;
    if (begins !== 1 || ends !== 1) return null;

    const start = markdown.indexOf(MARKER_BEGIN);
    const end = markdown.indexOf(MARKER_END);
    if (end <= start) return null;

    return markdown.slice(start + MARKER_BEGIN.length, end);
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

/**
 * script 名が「`pnpm <name>` / `pnpm run <name>`」としてトークン境界付きで現れるか (純関数)。
 *
 * 単純な部分一致にしないのは、`test` が `test:packages` に含まれて誤 green になるため。
 */
export function mentionsScript(section: string, name: string): boolean {
    return new RegExp(`pnpm (run )?${escapeRegExp(name)}(?![:\\w-])`).test(section);
}

/**
 * 非免除 script のうち、対象 section に現れないものを返す (純関数)。
 */
export function missingVerificationCommands(
    section: string,
    scriptNames: string[],
    exempt: Record<string, string>,
): string[] {
    return scriptNames.filter((name) => !(name in exempt)).filter((name) => !mentionsScript(section, name));
}

const packageJson = repoFile("package.json");
const scriptNames = packageScriptNames(packageJson);
const nonExempt = scriptNames.filter((name) => !(name in EXEMPT));

describe("検証コマンド列 ↔ package.json の同期", () => {
    it("V0: 各対象ファイルに同期マーカーがちょうど 1 組あること", () => {
        for (const target of TARGETS) {
            expect(verificationCommandsSection(repoFile(target)), `${target} のマーカーが 1 組でない`).not.toBeNull();
        }
    });

    for (const target of TARGETS) {
        it(`V1/V2: 非免除 script が ${target} のマーカー範囲内に全て現れること`, () => {
            const section = verificationCommandsSection(repoFile(target));
            expect(section, `${target} のマーカーが 1 組でない (V0 を先に直すこと)`).not.toBeNull();

            const missing = missingVerificationCommands(section as string, scriptNames, EXEMPT);
            expect(missing, `${target} の検証コマンド列に無い script: ${missing.join(", ")}`).toEqual([]);
        });
    }

    it("V3: EXEMPT の全 key が package.json の scripts に実在すること (stale 免除の検出)", () => {
        const stale = Object.keys(EXEMPT).filter((name) => !scriptNames.includes(name));
        expect(stale, `package.json に無い免除の残置: ${stale.join(", ")}`).toEqual([]);
    });

    it("V4: 空振り防止 — 非免除 script が 7 件以上あること", () => {
        expect(nonExempt.length).toBeGreaterThanOrEqual(7);
    });

    it("V5: 免除理由が 10 文字以上あること (理由なし免除を認めない)", () => {
        for (const [name, reason] of Object.entries(EXEMPT)) {
            expect(reason.trim().length, `免除 "${name}" の理由が短すぎる`).toBeGreaterThanOrEqual(10);
        }
    });
});

describe("走査関数の正負コントロール (検出器が空振りしていないこと)", () => {
    it("V6 正のコントロール: 未記載 script を違反として検出する", () => {
        const section = "`pnpm lint` / `pnpm typecheck`";
        expect(missingVerificationCommands(section, ["lint", "typecheck", "ghost:script"], {})).toEqual([
            "ghost:script",
        ]);
    });

    it("V7 負のコントロール: マーカー範囲外の記述は照合対象にならない", () => {
        const markdown = [
            "範囲外で `pnpm ghost:script` に言及している。",
            MARKER_BEGIN,
            "- 検証コマンド: `pnpm lint`",
            MARKER_END,
            "こちらも範囲外の `pnpm phantom`。",
        ].join("\n");

        const section = verificationCommandsSection(markdown);
        expect(section).not.toBeNull();
        expect(missingVerificationCommands(section as string, ["lint"], {})).toEqual([]);
        expect(missingVerificationCommands(section as string, ["ghost:script", "phantom"], {})).toEqual([
            "ghost:script",
            "phantom",
        ]);
    });

    it("トークン境界: `pnpm test:packages` だけでは `test` を満たさない (部分一致による誤 green の防止)", () => {
        expect(mentionsScript("`pnpm test:packages`", "test")).toBe(false);
        expect(mentionsScript("`pnpm test:packages`", "test:packages")).toBe(true);
        expect(mentionsScript("`pnpm run test`", "test")).toBe(true);
        // `pnpm build` は `build:packages` を満たさない (逆方向の誤 green も防ぐ)
        expect(mentionsScript("`pnpm build`", "build:packages")).toBe(false);
    });

    it("V0 負のコントロール: マーカーが無い / 二重にあると section を取り出せない", () => {
        expect(verificationCommandsSection("マーカーの無い文書")).toBeNull();
        expect(verificationCommandsSection(`${MARKER_BEGIN}a${MARKER_END}${MARKER_BEGIN}b${MARKER_END}`)).toBeNull();
        expect(verificationCommandsSection(`${MARKER_END}a${MARKER_BEGIN}`)).toBeNull();
    });
});
