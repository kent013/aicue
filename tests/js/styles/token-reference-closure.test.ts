import { describe, expect, it } from "vitest";
import {
    scanClassUsage,
    scanClassUsageSource,
    scanCssVarReferences,
    scanCssVarReferencesSource,
} from "./class-usage";
import { NON_TOKEN_WORD_CONTRACT } from "./inventory";

/**
 * 参照の閉包 (正典 i9) — 自リポジトリのスタイルと画面のコードが参照する token 名が、
 * すべて写像 (`resources/css/tokens.css` の `@theme`) の宣言集合へ解決することを検査する。
 *
 * 【なぜ要るか】綴り誤りは「無スタイル」として静かに消える。Tailwind は未知の utility を
 *   エラーにせず、単に生成しない。
 * 【解決の根拠は写像 1 か所だけ】他ファイルのローカル宣言 (style 属性 / 別 CSS の `:root`) を
 *   根拠に数えると、正本の外に token 空間が静かに育つ形が通ってしまう。
 * 【走査対象】
 *   - `resources/js`: 文字列リテラルの中の class トークン (class-usage.ts と同じ走査単位)
 *   - `resources/js` / `resources/css`: `var(--…)` 参照
 * 【契約表】token を指さない語は `NON_TOKEN_WORD_CONTRACT` へ**チャネル別**に全数登録する。
 *   Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は**登録しない** —
 *   写像の外の token 空間を参照する形なので落とすのが正しい。
 * 【本 gate が消費する診断】`scanCssVarReferences().diagnostics` (CSS var 走査)。
 *   class 走査の診断は `class-usage.test.ts` が消費する。
 * 【保証しないもの】
 *   - `resources/views` 配下 (Laravel 同梱メールテーマの独立パレット) は対象外
 *   - 変種の修飾の綴り (`hoverr:`) は見ない (Tailwind の名前空間で本アプリの写像ではない)
 *   - **走査単位の外 (動的に組み立てた class)**。deny で 0 件に固定しているのは
 *     `unsupportedEntryPoints()` が列挙する 3 入口 (`class:` ディレクティブ /
 *     class 合成ライブラリ / テーマ名前空間の接頭辞の内側への補間) **だけ**である。
 *     **静的な部分にテーマ名前空間の語を 1 つも持たない補間** (`` `${classes}` `` /
 *     `class={classes}`) は deny もせず単位も作らない = **非保証**である
 *     (class 記述だと静的に判別できないため。正本は class-usage.ts の「保証しないもの」)
 *   - 増えるのは**テーマ名前空間の接頭辞を持つ語だけ**である。`flex` / `px-3` / `gap-2` は
 *     接頭辞を持たないので母集団に入らない
 */

const CSS = "fixture.css";
const TS = "fixture.ts";

const unresolvedUtilities = (source: string, file: string): readonly string[] =>
    scanClassUsageSource(source, file)
        .occurrences.filter((o) => o.resolution.kind === "unresolved")
        .map((o) => o.utility);

const classFixture = (literal: string): readonly string[] =>
    unresolvedUtilities(`export const a = ${literal};\n`, TS);

describe("token-reference-closure: class トークンの閉包", () => {
    const scan = scanClassUsage();

    it("母集団が空でない (走査の空振り防止)", () => {
        expect(scan.files.length).toBeGreaterThan(0);
        expect(scan.occurrences.length).toBeGreaterThan(0);
    });

    it("テーマ名前空間の class トークンがすべて写像か契約表へ解決する", () => {
        const unresolved = scan.occurrences
            .filter((o) => o.resolution.kind === "unresolved")
            .map((o) =>
                o.resolution.kind === "unresolved"
                    ? `${o.file}: ${o.raw} (${o.resolution.reason})`
                    : "",
            )
            .sort();
        expect(
            [...new Set(unresolved)],
            "写像 (tokens.css の @theme) にも契約表にも解決しない語がある。" +
                "綴りを直すか、token を指さない語なら NON_TOKEN_WORD_CONTRACT へ理由つきで登録すること",
        ).toEqual([]);
    });
});

describe("token-reference-closure: var(--…) 参照の閉包", () => {
    const scan = scanCssVarReferences();

    it("走査根が 2 本とも生きており、参照の総数が 0 でない", () => {
        expect([...scan.perRoot.keys()].sort()).toEqual(["resources/css", "resources/js"]);
        expect(scan.files.length, "走査したファイルが 0 件 (走査の空振り)").toBeGreaterThan(0);
        for (const [root, count] of scan.perRoot) {
            expect(count, `${root} からファイルが 1 件も取れない`).toBeGreaterThan(0);
        }
        // 根ごとの参照件数の非空は要求しない (参照を正当に消しただけで赤くなるため)。
        // 要求するのは総数が 0 でないことだけで、これはドメインの不変条件である。
        expect(scan.references.length, "var() 参照が 1 件も取れない").toBeGreaterThan(0);
    });

    it("CSS var 走査の診断が 1 件も無い (本 gate が診断の消費先である)", () => {
        expect(scan.diagnostics).toEqual([]);
    });

    it("var(--…) 参照がすべて写像か契約表へ解決する", () => {
        const unresolved = scan.references
            .filter((r) => r.resolution.kind === "unresolved")
            .map((r) => `${r.file}: var(${r.name})`)
            .sort();
        expect([...new Set(unresolved)]).toEqual([]);
    });
});

describe("token-reference-closure: 契約表の健全性", () => {
    const scan = scanClassUsage();
    const varScan = scanCssVarReferences();

    it("契約表に重複が無い", () => {
        const keys = NON_TOKEN_WORD_CONTRACT.map((entry) =>
            entry.kind === "class-word" ? `class:${entry.word}` : `var:${entry.name}`,
        );
        expect(new Set(keys).size).toBe(keys.length);
    });

    it("契約表の登録に理由が書かれている", () => {
        for (const entry of NON_TOKEN_WORD_CONTRACT) {
            const label = entry.kind === "class-word" ? entry.word : entry.name;
            expect(entry.reason.length, `${label}: 理由`).toBeGreaterThan(0);
        }
    });

    it("class-word の登録が class トークンとして 1 回以上出現し、写像へは解決しない", () => {
        // チャネル別に判定する。別のチャネルでの出現によって登録が生きているように見える形を作らない。
        const resolvedByContract = new Set(
            scan.occurrences.flatMap((o) =>
                o.resolution.kind === "contract" ? [o.resolution.word] : [],
            ),
        );
        for (const entry of NON_TOKEN_WORD_CONTRACT) {
            if (entry.kind !== "class-word") continue;
            expect(
                resolvedByContract.has(entry.word),
                `${entry.word}: class トークンとして 1 件も出現しない (冗長な登録)`,
            ).toBe(true);
        }
    });

    it("css-variable の登録が var() 参照として 1 回以上出現し、写像へは解決しない", () => {
        const resolvedByContract = new Set(
            varScan.references.flatMap((r) =>
                r.resolution.kind === "contract" ? [r.resolution.word] : [],
            ),
        );
        for (const entry of NON_TOKEN_WORD_CONTRACT) {
            if (entry.kind !== "css-variable") continue;
            expect(
                resolvedByContract.has(entry.name),
                `${entry.name}: var() 参照として 1 件も出現しない (冗長な登録)`,
            ).toBe(true);
        }
    });
});

describe("token-reference-closure: 負のコントロール (固定検体)", () => {
    it("Tailwind 既定テーマの色語 (text-white) は通らない", () => {
        expect(classFixture('"bg-primary text-white"')).toEqual(["text-white"]);
    });

    it("綴り誤り (bg-primaryy) は通らない", () => {
        expect(classFixture('"bg-primaryy"')).toEqual(["bg-primaryy"]);
    });

    it("契約表の語 (text-center 等) は誤検出しない", () => {
        expect(classFixture('"text-center text-left text-right rounded-full border-2"')).toEqual([]);
    });

    it("変種 / 重要度 / 不透明度の 3 形を別々に固定する", () => {
        expect(classFixture('"sm:text-center"')).toEqual([]);
        expect(classFixture('"!text-center"')).toEqual([]);
        // 色でない utility への不透明度修飾は「同じ語」として通さない
        expect(classFixture('"text-center/50"')).toEqual(["text-center"]);
    });

    it("写像に無い CSS 変数の参照は通らない", () => {
        const scan = scanCssVarReferencesSource("a { color: var(--color-does-not-exist); }", CSS);
        expect(scan.references.map((r) => r.resolution.kind)).toEqual(["unresolved"]);
    });

    it("別ファイルのローカル宣言を解決の根拠に数えない", () => {
        // 写像 1 か所だけという境界そのものを pin する。
        const scan = scanCssVarReferencesSource(
            ":root { --color-foo: red; }\na { color: var(--color-foo); }",
            CSS,
        );
        expect(scan.references.map((r) => r.resolution.kind)).toEqual(["unresolved"]);
    });

    it("Svelte の <style> の中の未知 var も見える (走査対象から外れない)", () => {
        // `<style>` を歩かないと「resources/js の var 参照を閉包する」という主張と食い違う。
        const scan = scanCssVarReferencesSource(
            "<div></div>\n<style>.x { color: var(--color-does-not-exist); }</style>\n",
            "fixture.svelte",
        );
        expect(scan.references.map((r) => r.name)).toEqual(["--color-does-not-exist"]);
        expect(scan.references.map((r) => r.resolution.kind)).toEqual(["unresolved"]);
    });

    it("Svelte の <style> の中の写像 token は解決する (誤検出しない)", () => {
        const scan = scanCssVarReferencesSource(
            "<div></div>\n<style>.x { color: var(--color-primary); }</style>\n",
            "fixture.svelte",
        );
        expect(scan.references.map((r) => r.resolution.kind)).toEqual(["color"]);
        expect(scan.diagnostics).toEqual([]);
    });

    it("任意値の中のコロンを持つ未知 token は候補ごと消えずに落ちる", () => {
        // 変種の分割が角括弧を見ないと `text-[color:#ffffff]` が監視対象から外れ、
        // hex 直書きが無検査で通ってしまう。
        expect(classFixture('"text-[color:#ffffff]"')).toEqual(["text-[color:#ffffff]"]);
    });

    it("チャネルが違う登録は解決の根拠にならない", () => {
        // class-word の登録は var() 参照を救わない
        expect(
            scanCssVarReferencesSource("a { color: var(--text-center); }", CSS).references.map(
                (r) => r.resolution.kind,
            ),
        ).toEqual(["unresolved"]);
        // css-variable の登録は class トークンを救わない
        expect(classFixture('"text-app-sidebar-w"')).toEqual(["text-app-sidebar-w"]);
    });

    it("var() 走査の受理契約 (関数トークンの境界・カンマ・fallback・未終端)", () => {
        const names = (source: string): readonly string[] =>
            scanCssVarReferencesSource(source, CSS).references.map((r) => r.name);
        const reasons = (source: string): readonly string[] =>
            scanCssVarReferencesSource(source, CSS).diagnostics.map((d) => d.reason);

        expect(names('a { content: "var(--x)"; }')).toEqual([]);
        expect(names("a { color: var(--color-primary /* c */); }")).toEqual(["--color-primary"]);
        expect(names("a { color: var(--color-primary, var(--color-neutral)); }")).toEqual([
            "--color-primary",
            "--color-neutral",
        ]);
        // 閉じない `var(` は at-rule の条件式で確かめる (宣言側は postcss の parse が先に落ちる)
        expect(reasons("@media var(--color-primary { a { color: red; } }")).toEqual([
            "unterminated-function",
        ]);
        expect(reasons("a { color: var(--color-primary; }")).toEqual(["css-parse-failed"]);
        expect(names('@theme { --f: "a,b", c; }')).toEqual([]);
        expect(reasons('@theme { --f: "a,b", c; }')).toEqual([]);
        expect(names("@media (min-width: var(--color-primary)) { a { color: red; } }")).toEqual([
            "--color-primary",
        ]);
        expect(names("a { color: myvar(--color-primary); }")).toEqual([]);
        expect(reasons("a { color: myvar(--color-primary); }")).toEqual([]);
        expect(reasons("a { color: var(--color-primary garbage); }")).toEqual(["unresolvable-var"]);
        expect(names("a { color: var(--color-primary, b, c); }")).toEqual(["--color-primary"]);
    });

    it("列挙外の at-rule の条件式に var( があれば診断になる (無視しない)", () => {
        expect(
            scanCssVarReferencesSource("@page var(--color-primary) { size: a; }", CSS).diagnostics.map(
                (d) => d.reason,
            ),
        ).toEqual(["unsupported-at-rule-params"]);
    });
});
