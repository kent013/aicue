import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ESLint } from "eslint";
import globals from "globals";

/*
 * svelte-no-undef-gate — .svelte の未定義識別子検出を config レベルで固定する。
 *
 * 背景: .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
 * 未定義識別子を捕まえる機構は eslint の no-undef **だけ**であり、
 * これが外れると .svelte 全体が無検査に戻る (spirux:T1054 = SSO 接続追加画面の
 * クラッシュと同型の事故が再発する)。
 *
 * 検査する不変条件:
 *   A. [.svelte のみ] no-undef が error
 *   B. [.svelte のみ] languageOptions.globals が globals.browser と **完全一致**
 *      (型専用名を混ぜて no-undef を骨抜きにしない。追加は eslint.config.js の
 *       APP_RUNTIME_GLOBALS へ理由付きで登録し、本 gate 側も同時に更新する)
 *   C. [lint 対象の全ファイル] linterOptions.noInlineConfig が true
 *      — A/B を inline コメントで黙らせないための **前提条件**。
 *      `pnpm lint` = `eslint resources/js` なので、走査範囲も
 *      **resources/js 配下 × eslint.config.js が files で対象にしている全拡張子**
 *      (.svelte / .js / .mjs / .cjs / .ts / .jsx / .tsx) に一致させる。
 *      .svelte だけ見ると .ts 向け file-scoped override での復活を見逃す。
 *      lint されないファイル (tests/js 等) は ESLint が directive を読まないので対象外。
 *   D. 走査対象が 0 件でない (空振り gate を green として扱わない)
 *
 * gate の名前が指す中心は「.svelte の no-undef」だが、
 * それを支える C は前提の適用範囲 (= lint 対象全体) で検査する。
 * **lint 対象を広げたら本 gate の LINT_TARGET_EXTENSIONS / 走査ルートも同時に広げること。**
 *
 * 実装は laravel-claude-template のものと **別実装**。同一不変条件・別実装の
 * divergence として docs/template-divergence.md D11 に記録している。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");
const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");

/** 検査対象に落とし込んだ実効 config の view (純関数への入力) */
interface ResolvedConfigView {
    readonly rules?: Record<string, unknown>;
    readonly linterOptions?: { readonly noInlineConfig?: boolean };
    readonly languageOptions?: { readonly globals?: Record<string, unknown> };
}

/** 期待する globals キー集合 (allowlist。eslint.config.js の svelteGlobals と一対一) */
const EXPECTED_GLOBAL_KEYS = Object.keys(globals.browser).sort();

/**
 * lint 対象の拡張子 (= eslint.config.js の files が対象にしている集合)。
 * `pnpm lint` の対象を広げたらここも広げること。
 */
const LINT_TARGET_EXTENSIONS = [".svelte", ".js", ".mjs", ".cjs", ".ts", ".jsx", ".tsx"] as const;

/**
 * [C] inline の eslint-disable が効かないこと。**lint 対象の全拡張子**に適用する
 * (`pnpm lint` = `eslint resources/js` の範囲 × LINT_TARGET_EXTENSIONS)。
 */
function assertNoInlineConfig(resolved: ResolvedConfigView): string[] {
    return resolved.linterOptions?.noInlineConfig === true
        ? []
        : ["linterOptions.noInlineConfig が true でない (inline の eslint-disable が効いてしまう)"];
}

/**
 * [A][B] .svelte 固有の不変条件を検査し、違反理由を返す (空配列 = 適合)。
 * ESLint の設定マージ規則ではなく **解決結果**だけを見る純関数。
 */
function assertSvelteNoUndefConfig(resolved: ResolvedConfigView): string[] {
    const problems: string[] = [];

    const noUndef = resolved.rules?.["no-undef"];
    // flat config の解決結果では severity は数値 (2 = error) を含む配列で返る
    const severity = Array.isArray(noUndef) ? noUndef[0] : noUndef;
    if (severity !== 2 && severity !== "error") {
        problems.push(`no-undef が error でない (実効値: ${JSON.stringify(noUndef)})`);
    }

    const actualKeys = Object.keys(resolved.languageOptions?.globals ?? {}).sort();
    const extra = actualKeys.filter((k) => !EXPECTED_GLOBAL_KEYS.includes(k));
    const missing = EXPECTED_GLOBAL_KEYS.filter((k) => !actualKeys.includes(k));
    if (extra.length > 0) {
        problems.push(
            `globals に globals.browser 外のキーがある: ${extra.join(", ")} ` +
                `(型専用名の登録は禁止。実行時グローバルなら eslint.config.js の ` +
                `APP_RUNTIME_GLOBALS へ理由付きで登録し、本テストの期待値も同時に更新すること)`,
        );
    }
    if (missing.length > 0) {
        problems.push(`globals に globals.browser のキーが不足: ${missing.slice(0, 5).join(", ")}…`);
    }

    return problems;
}

async function sourceFiles(dir: string, exts: readonly string[]): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && exts.some((ext) => e.name.endsWith(ext))) {
            out.push(path.join(e.parentPath, e.name));
        }
    }
    return out.sort(); // 失敗メッセージを走査順の環境差で揺らさない
}

/** 実効設定を解決する。解決できない場合は silent skip せず明瞭に fail させる。 */
async function resolveConfig(eslint: ESLint, file: string): Promise<ResolvedConfigView> {
    const resolved: unknown = await eslint.calculateConfigForFile(file);
    if (typeof resolved !== "object" || resolved === null) {
        throw new Error(
            `実効設定を解決できなかった: ${path.relative(REPO_ROOT, file)} ` +
                `(eslint.config.js の ignores に入っていないか確認すること)`,
        );
    }
    return resolved as ResolvedConfigView;
}

describe("architecture/svelte-no-undef-gate", () => {
    it("[A][B] resources/js 配下の全 .svelte で no-undef=error かつ globals が globals.browser と完全一致", async () => {
        const files = await sourceFiles(RESOURCES_JS, [".svelte"]);
        // 空振り防止: 走査が 0 件なら gate は何も守っていない
        expect(
            files.length,
            "resources/js 配下に .svelte が 1 件も無い (走査が空振りしている)",
        ).toBeGreaterThan(0);

        const eslint = new ESLint({ cwd: REPO_ROOT });
        const offenders: string[] = [];
        for (const file of files) {
            for (const problem of assertSvelteNoUndefConfig(await resolveConfig(eslint, file))) {
                offenders.push(`${path.relative(REPO_ROOT, file)}: ${problem}`);
            }
        }
        expect(
            offenders,
            `.svelte の未定義識別子検出が無効化されている。eslint.config.js を確認すること: \n` +
                offenders.join("\n"),
        ).toEqual([]);
    });

    it("[C] lint 対象 (resources/js × 全 lint 拡張子) で inline の eslint-disable が効かない", async () => {
        // noInlineConfig は A/B を inline コメントで黙らせないための前提条件。
        // .svelte だけ見ると .ts 等向けの file-scoped override での復活を見逃す。
        const files = await sourceFiles(RESOURCES_JS, LINT_TARGET_EXTENSIONS);
        expect(
            files.length,
            "resources/js 配下に lint 対象ファイルが 1 件も無い (走査が空振りしている)",
        ).toBeGreaterThan(0);

        const eslint = new ESLint({ cwd: REPO_ROOT });
        const offenders: string[] = [];
        for (const file of files) {
            for (const problem of assertNoInlineConfig(await resolveConfig(eslint, file))) {
                offenders.push(`${path.relative(REPO_ROOT, file)}: ${problem}`);
            }
        }
        expect(
            offenders,
            `inline の eslint-disable が有効に戻っている。ルールを黙らせる唯一の手段は ` +
                `eslint.config.js の file-scoped override (3 条件を満たすこと): \n` +
                offenders.join("\n"),
        ).toEqual([]);
    });

    /*
     * 負のコントロール: 検査器が実際に点灯することを、解決結果を加工した
     * plain object で確認する (ESLint のマージ規則は試験対象にしない)。
     */
    it("負のコントロール: no-undef 解除 / globals 汚染 / noInlineConfig 無効を検出する", () => {
        const sound: ResolvedConfigView = {
            rules: { "no-undef": [2] },
            linterOptions: { noInlineConfig: true },
            languageOptions: { globals: { ...globals.browser } },
        };
        expect(assertSvelteNoUndefConfig(sound), "正のコントロール (svelte)").toEqual([]);
        expect(assertNoInlineConfig(sound), "正のコントロール (noInlineConfig)").toEqual([]);

        expect(
            assertSvelteNoUndefConfig({ ...sound, rules: { "no-undef": [0] } }),
            "no-undef=off",
        ).toHaveLength(1);
        expect(
            assertSvelteNoUndefConfig({
                ...sound,
                languageOptions: {
                    globals: { ...globals.browser, MediaTrackConstraints: "readonly" },
                },
            }),
            "型専用名の混入",
        ).toHaveLength(1);
        expect(
            assertNoInlineConfig({ ...sound, linterOptions: { noInlineConfig: false } }),
            "noInlineConfig=false",
        ).toHaveLength(1);
    });
});
