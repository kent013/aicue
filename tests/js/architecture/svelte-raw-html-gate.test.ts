import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ESLint } from "eslint";

/*
 * svelte-raw-html-gate — 生の HTML を DOM へ差し込む構文 ({@html}) の全面禁止が
 * **実効である**ことを、config と振る舞いの両方から固定する。
 *
 * 背景: この構文は文字列を DOM 木として解釈させるので、値の出どころが 1 か所でも
 * 汚れていれば script がそのまま実行される。撮影 PWA は同一オリジン・セッション認証なので、
 * XSS の成立は撮影導線の資格情報にそのまま届く。
 *
 * 検査する不変条件:
 *   A. [config・全数] C が収集した resources/js 配下の .svelte **全件**について
 *      calculateConfigForFile() の実効 severity が error である。
 *      代表 1 件では、特定ファイル向け override で規則を off にされたときに見逃す。
 *   B. [振る舞い] 禁止構文を含む合成入力を実際に lint すると error になる。
 *      **無効化コメント 3 形式を付けても error のまま**である (下の DISABLE_FORMS)。
 *   B'. [負例の裏取り] 同じ 3 形式が noInlineConfig:false の**対照条件**では
 *      実際に error を消せる。これが無いと「元から解釈されていない文字列」を
 *      負例と称して緑になる (検出力の空振り)。
 *   B''. [形式選定の根拠] HTML コメント形式の無効化指示は、この lint 構成では
 *      **対照条件でも効かない** (eslint-plugin-svelte の comment-directive を
 *      有効化していないため)。よって B/B' の負例には使えない。この事実を固定しておくと、
 *      将来 comment-directive を有効化したときに B'' が赤くなり、
 *      「その形式を B/B' の負例へ移せ」という信号になる。
 *   C. [実ファイル] resources/js 配下の .svelte 全数に禁止構文が 0 件。
 *      判定は純関数 containsRawHtmlSink() が行う。
 *   C'. [C の検出力] containsRawHtmlSink() を合成入力で恒久的に裏取りする。
 *      実ファイルが 0 件になった後も検出器が生きていることを保証する
 *      (実ファイル 0 件の状態では C だけでは検出器の生存を確かめられない)。
 *   D. [正例・lint] 禁止構文を含まない規定どおりの入力を ESLint が誤検出しない。
 *   E. [fail-closed] 走査根 resources/js が解決できない / 母集団が 0 件 /
 *      config 解決に失敗した場合は**落とす**。
 *   F. [fail-closed・lint] すべての lintText 結果について
 *      「lint が実際に走って結果が使える」ことを、**対象 rule の件数を見る前に**確認する
 *      (fatalErrorCount === 0 / fatal な message が無い / その filePath が ignored でない)。
 *      ESLint は構文解析エラーを throw せず fatal message として返すため、
 *      「対象 rule が 0 件」だけを見ると解析失敗も ignored も正常扱いしてしまう (fail-open)。
 *   F'. [F の検出力] 判定は純関数 assertLintExecutionUsable() が行い、
 *      合成入力で正負を恒久的に裏取りする (B/B'/D はすべて正常に parse される入力なので、
 *      F の検査を壊しても実入力では気付けない)。
 *
 * **許可一覧 (allowlist / exemption inventory) の口は持たない** (正典が明記する方針)。
 * 例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む
 * 別のセキュリティ設計としてレビューを通すこと。
 *
 * 走査対象: resources/js 配下の `.svelte` 全数 (git 追跡かどうかは見ない)。
 * 検出の区切り: 文字列 `{@html` の出現。
 *   **コメント内・文字列リテラル内も違反として数える** — 構文解析器を持たない字面走査であり、
 *   目標値が 0 件なので拾いすぎる方向へ倒すのは AGENTS.md (b) の許す側である。
 *   帰結として **resources/js 配下の .svelte では説明のためであっても禁止構文の字面を書けない**
 *   (コメントでは「raw HTML 挿入構文」と呼び名で書く)。
 *   字面を書いてよいのは走査対象の外 — eslint.config.js / DESIGN.md / 本 gate 自身である
 *   (本 gate は負例入力として字面が**必要**なので、自分自身を走査根に含めない)。
 *
 * 保証しないもの (誇張しない):
 *   - 禁止構文**以外**の raw HTML sink (innerHTML 直代入 / svelte:element の動的タグ /
 *     document.write 等) には**無言で効かない**。
 *   - resources/js の外の .svelte は走査しない (lint 対象と一致させている)。
 *   - browser が画像文脈の SVG をどう扱うかは本 gate の対象ではない。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");
const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");

/** 検査対象の lint 規則 (禁止の正本は eslint.config.js)。 */
const RULE = "svelte/no-at-html-tags";

/**
 * 合成入力に使う仮想パス。**実在させない** —
 * resources/js 配下に fixture ファイルを置くと検査 C の母集団に混ざり、
 * 「実ファイル 0 件」の意味が壊れるため (lintText の filePath は仮想でよい)。
 */
const VIRTUAL_SVELTE = path.join(RESOURCES_JS, "__svelte-raw-html-gate-virtual__.svelte");

/** 禁止構文を 1 件含む合成 .svelte 本文を組み立てる (script 先頭へ prelude を差し込む)。 */
function violatingSource(scriptPrelude = ""): string {
    return [
        `<script lang="ts">`,
        scriptPrelude,
        `    const value = "<b>x</b>";`,
        `</script>`,
        ``,
        `<div>{@html value}</div>`,
        ``,
    ].join("\n");
}

/**
 * B / B' で使う無効化コメント 3 形式。
 *
 * **script ブロック内の JS コメント**を採る。HTML コメント形式
 * (`<!-- eslint-disable ... -->`) はこの lint 構成では対照条件でも解釈されず
 * (comment-directive 未有効)、負例として無効だからである (B'' が実測で固定する)。
 */
const DISABLE_FORMS: readonly { readonly name: string; readonly source: string }[] = [
    { name: "全規則の無効化 (/* eslint-disable */)", source: violatingSource(`    /* eslint-disable */`) },
    {
        name: `規則名指しの無効化 (/* eslint-disable ${RULE} */)`,
        source: violatingSource(`    /* eslint-disable ${RULE} */`),
    },
    {
        name: `inline の severity 上書き (/* eslint ${RULE}: "off" */)`,
        source: violatingSource(`    /* eslint ${RULE}: "off" */`),
    },
] as const;

/** B'' — この lint 構成では対照条件でも効かない (= 負例に使えない) 形式。 */
const INERT_HTML_COMMENT_FORMS: readonly { readonly name: string; readonly source: string }[] = [
    {
        name: "HTML コメントの全規則無効化",
        source: `<!-- eslint-disable -->\n${violatingSource()}`,
    },
    {
        name: "HTML コメントの規則名指し無効化",
        source: `<!-- eslint-disable ${RULE} -->\n${violatingSource()}`,
    },
    {
        name: "HTML コメントの次行無効化",
        source: [
            `<script lang="ts">`,
            `    const value = "<b>x</b>";`,
            `</script>`,
            ``,
            `<!-- eslint-disable-next-line ${RULE} -->`,
            `<div>{@html value}</div>`,
            ``,
        ].join("\n"),
    },
] as const;

/** 本文に raw HTML 挿入構文の字面が含まれるか (検査 C の判定の正本。C' が裏取りする)。 */
export function containsRawHtmlSink(source: string): boolean {
    return source.includes("{@html");
}

/** assertLintExecutionUsable() が受け取る lint 結果の最小 view。 */
interface LintExecutionView {
    readonly fatalErrorCount: number;
    readonly messages: readonly { readonly fatal?: boolean }[];
}

/**
 * lint 結果が「判定に使える」か (検査 F の判定の正本。F' が裏取りする)。
 * 対象 rule の件数を数える**前に**通す。違反理由を返す (空配列 = 使える)。
 */
export function assertLintExecutionUsable(
    result: LintExecutionView,
    isIgnored: boolean,
): string[] {
    const problems: string[] = [];

    if (result.fatalErrorCount !== 0) {
        problems.push(`fatalErrorCount が ${result.fatalErrorCount} (構文解析に失敗している)`);
    }
    if (result.messages.some((message) => message.fatal === true)) {
        problems.push("fatal な message がある (構文解析に失敗している)");
    }
    if (isIgnored) {
        problems.push("対象パスが ignored (lint されていないので rule 件数 0 は無意味)");
    }

    return problems;
}

/** 走査根から .svelte を再帰収集する。根が解決できなければ落とす ([E] fail-closed)。 */
async function svelteFiles(root: string): Promise<string[]> {
    const stats = await fs.stat(root).catch((cause: unknown) => {
        throw new Error(`走査根を解決できない: ${path.relative(REPO_ROOT, root)}`, { cause });
    });
    if (!stats.isDirectory()) {
        throw new Error(`走査根がディレクトリでない: ${path.relative(REPO_ROOT, root)}`);
    }

    const out: string[] = [];
    for (const entry of await fs.readdir(root, { recursive: true, withFileTypes: true })) {
        if (entry.isFile() && entry.name.endsWith(".svelte")) {
            out.push(path.join(entry.parentPath, entry.name));
        }
    }

    return out.sort(); // 失敗メッセージを走査順の環境差で揺らさない
}

/** 母集団 (= A と C が**同じものを**判定に使う。AGENTS.md (d))。 */
async function population(): Promise<string[]> {
    const files = await svelteFiles(RESOURCES_JS);
    expect(files.length, "resources/js 配下に .svelte が 1 件も無い (走査が空振りしている)").toBeGreaterThan(0);

    return files;
}

/**
 * 合成入力を 1 本 lint する。結果は **F を通してから**返す
 * (rule 件数を数える前に「lint が実際に走った」ことを確かめる)。
 */
async function lintVirtual(eslint: ESLint, source: string): Promise<ESLint.LintResult> {
    const results = await eslint.lintText(source, { filePath: VIRTUAL_SVELTE });
    const result = results[0];
    if (result === undefined) {
        throw new Error("lintText が結果を返さなかった");
    }

    const problems = assertLintExecutionUsable(result, await eslint.isPathIgnored(VIRTUAL_SVELTE));
    expect(problems, `[F] lint 結果が判定に使えない: ${problems.join(" / ")}`).toEqual([]);

    return result;
}

/** lint 結果のうち検査対象 rule の error 件数。 */
function ruleErrorCount(result: ESLint.LintResult): number {
    return result.messages.filter((message) => message.ruleId === RULE && message.severity === 2)
        .length;
}

describe("architecture/svelte-raw-html-gate", () => {
    it(`[A][E] resources/js 配下の全 .svelte で ${RULE} が error`, async () => {
        const files = await population();
        const eslint = new ESLint({ cwd: REPO_ROOT });

        const offenders: string[] = [];
        for (const file of files) {
            const resolved: unknown = await eslint.calculateConfigForFile(file);
            if (typeof resolved !== "object" || resolved === null) {
                // [E] 解決できない形は落とす (無言で候補から外さない)
                throw new Error(`実効設定を解決できなかった: ${path.relative(REPO_ROOT, file)}`);
            }

            const rules = (resolved as { rules?: Record<string, unknown> }).rules;
            const entry = rules?.[RULE];
            const severity = Array.isArray(entry) ? entry[0] : entry;
            if (severity !== 2 && severity !== "error") {
                offenders.push(
                    `${path.relative(REPO_ROOT, file)}: 実効 severity が error でない (${JSON.stringify(entry)})`,
                );
            }
        }

        expect(
            offenders,
            `生の HTML を DOM へ差し込む構文の禁止が無効化されている。eslint.config.js を確認すること ` +
                `(許可一覧の口は持たない方針である):\n${offenders.join("\n")}`,
        ).toEqual([]);
    });

    it("[B][F] 禁止構文は無効化コメント 3 形式を付けても error のまま", async () => {
        const eslint = new ESLint({ cwd: REPO_ROOT });

        const bare = await lintVirtual(eslint, violatingSource());
        expect(ruleErrorCount(bare), "素の違反入力が error にならない").toBeGreaterThan(0);

        for (const form of DISABLE_FORMS) {
            const result = await lintVirtual(eslint, form.source);
            expect(
                ruleErrorCount(result),
                `無効化コメントで error が消えた: ${form.name} ` +
                    `(eslint.config.js の linterOptions.noInlineConfig を確認すること)`,
            ).toBeGreaterThan(0);
        }
    });

    it("[B'][F] 対照条件 (noInlineConfig:false) では同じ 3 形式が実際に error を消す", async () => {
        const eslint = new ESLint({
            cwd: REPO_ROOT,
            overrideConfig: { linterOptions: { noInlineConfig: false } },
        });

        // 対照条件でも素の違反は error である (対照条件そのものが壊れていないことの確認)
        const bare = await lintVirtual(eslint, violatingSource());
        expect(ruleErrorCount(bare), "対照条件で素の違反が error にならない").toBeGreaterThan(0);

        for (const form of DISABLE_FORMS) {
            const result = await lintVirtual(eslint, form.source);
            expect(
                ruleErrorCount(result),
                `対照条件でも error が消えない = この形式は負例として無効である: ${form.name} ` +
                    `(B の「無効化できない」が空振りしていないか、形式を選び直すこと)`,
            ).toBe(0);
        }
    });

    it("[B''][F] HTML コメント形式の無効化指示は対照条件でも効かない (負例に使えない根拠)", async () => {
        const eslint = new ESLint({
            cwd: REPO_ROOT,
            overrideConfig: { linterOptions: { noInlineConfig: false } },
        });

        for (const form of INERT_HTML_COMMENT_FORMS) {
            const result = await lintVirtual(eslint, form.source);
            expect(
                ruleErrorCount(result),
                `対照条件で HTML コメント形式が効くようになった: ${form.name}。` +
                    `eslint-plugin-svelte の comment-directive を有効化したなら、` +
                    `この形式を DISABLE_FORMS へ移して B/B' の負例に加えること`,
            ).toBeGreaterThan(0);
        }
    });

    it("[C][E] resources/js 配下の .svelte に禁止構文が 0 件", async () => {
        const files = await population();

        const offenders: string[] = [];
        for (const file of files) {
            if (containsRawHtmlSink(await fs.readFile(file, "utf8"))) {
                offenders.push(path.relative(REPO_ROOT, file));
            }
        }

        expect(
            offenders,
            `生の HTML を DOM へ差し込む構文が書かれている。サーバ生成の SVG を描くなら ` +
                `components/atoms/QrCodeImage.svelte を使うこと。` +
                `なお本 gate は**字面**で数えるので、説明のためのコメントにも書けない:\n` +
                offenders.join("\n"),
        ).toEqual([]);
    });

    it("[C'] containsRawHtmlSink() の検出力 (実ファイルが 0 件になった後の生存保証)", () => {
        // 検出契約は「部分文字列 `{@html` を含めば違反」である。
        // したがって禁止文字列を**内包する**形はすべて true 側であり、正例ではない。
        const violating = [
            "<div>{@html value}</div>",
            "<!-- 説明のために {@html} と書いた -->",
            `<script lang="ts">\n    const s = "{@html}";\n</script>`,
            "<div>{@htmlish value}</div>", // 接尾辞つき (禁止文字列を内包する)
            "<div>x{@html value}</div>", // 接頭辞つき
        ];
        for (const source of violating) {
            expect(containsRawHtmlSink(source), `違反を見逃した: ${source}`).toBe(true);
        }

        const clean = [
            "<div>{name}</div>",
            "{@const x = 1}",
            "{@render children()}",
            "{#if cond}<span>y</span>{/if}",
            "<div>{@htm value}</div>", // 禁止文字列を内包しない近い綴り
            "<div>{ @html value}</div>", // 区切りが違う (字面一致しない)
        ];
        for (const source of clean) {
            expect(containsRawHtmlSink(source), `誤検出した: ${source}`).toBe(false);
        }
    });

    it("[D][F] 禁止構文を含まない規定どおりの入力を誤検出しない", async () => {
        const eslint = new ESLint({ cwd: REPO_ROOT });
        const source = [
            `<script lang="ts">`,
            `    const items = [{ id: 1, label: "a" }];`,
            `    const cond = true;`,
            `</script>`,
            ``,
            `{#if cond}`,
            `    {#each items as item (item.id)}`,
            `        {@const label = item.label}`,
            `        <span>{label}</span>`,
            `    {/each}`,
            `{/if}`,
            ``,
        ].join("\n");

        const result = await lintVirtual(eslint, source);
        expect(ruleErrorCount(result), "正例を違反と判定した").toBe(0);
    });

    it("[E] 走査根が解決できなければ落とす", async () => {
        await expect(svelteFiles(path.join(RESOURCES_JS, "__does-not-exist__"))).rejects.toThrow(
            /走査根を解決できない/,
        );
    });

    it("[F'] assertLintExecutionUsable() の検出力", () => {
        expect(
            assertLintExecutionUsable({ fatalErrorCount: 0, messages: [{}] }, false),
            "正のコントロール",
        ).toEqual([]);

        expect(
            assertLintExecutionUsable({ fatalErrorCount: 1, messages: [] }, false),
            "fatalErrorCount > 0",
        ).toHaveLength(1);
        expect(
            assertLintExecutionUsable({ fatalErrorCount: 0, messages: [{ fatal: true }] }, false),
            "fatal な message",
        ).toHaveLength(1);
        expect(
            assertLintExecutionUsable({ fatalErrorCount: 0, messages: [] }, true),
            "ignored なパス",
        ).toHaveLength(1);
    });
});
