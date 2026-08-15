import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import {
    FILE_SCOPED_ALLOWLIST,
    THEME_PATTERNS,
    UNIVERSAL_PATTERNS,
    isSingleClassToken,
    stripAllowlisted,
} from "../support/ds-purity";

/**
 * resources/js 配下 (components / pages / lib) の DS purity を機械検証する。
 *
 * - UNIVERSAL_PATTERNS: token 迂回の禁止 (テーマ非依存、常時適用)
 * - THEME_PATTERNS: 既定テーマ由来の制約 (影/gradient/rounded ramp/typography ramp)
 *
 * 例外は tests/js/support/ds-purity.ts の FILE_SCOPED_ALLOWLIST で管理する (出荷時 0 件)。
 * inline SVG の統制は svg-inline-allowlist.test.ts (atoms/icons/ 例外 + allowlist) が担う。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
const SCAN_EXTENSIONS = new Set([".svelte", ".ts"]);

function listFiles(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    const files: string[] = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true, recursive: true })) {
        if (!entry.isFile()) continue;
        if (!SCAN_EXTENSIONS.has(path.extname(entry.name))) continue;
        files.push(path.join(entry.parentPath, entry.name));
    }
    return files;
}

function relPath(file: string): string {
    return path.relative(JS_ROOT, file);
}

const allFiles = listFiles(JS_ROOT);

describe("DS purity", () => {
    it("UNIVERSAL: token 迂回 (raw palette / hex / arbitrary z / 静的 inline style) が無い", () => {
        const violations: string[] = [];
        for (const file of allFiles) {
            const content = stripAllowlisted(relPath(file), fs.readFileSync(file, "utf-8"));
            for (const [pattern, message] of UNIVERSAL_PATTERNS) {
                const m = content.match(pattern);
                if (m) {
                    violations.push(`${relPath(file)}: "${m[0]}" — ${message}`);
                }
            }
        }
        expect(violations).toEqual([]);
    });

    it("THEME: 既定テーマの制約 (影/gradient/scale/rounded ramp/typography ramp) を満たす", () => {
        const violations: string[] = [];
        for (const file of allFiles) {
            const content = stripAllowlisted(relPath(file), fs.readFileSync(file, "utf-8"));
            for (const [pattern, message] of THEME_PATTERNS) {
                const m = content.match(pattern);
                if (m) {
                    violations.push(`${relPath(file)}: "${m[0]}" — ${message}`);
                }
            }
        }
        expect(violations).toEqual([]);
    });

    it("走査が空振りしていない (母集団が空でなく、代表ファイルを含む)", () => {
        expect(allFiles.length).toBeGreaterThan(0);
        const rels = allFiles.map(relPath);
        // 免罪の対象が母集団から落ちたら赤くする
        // (落ちると免罪が意味を失ったことに誰も気づかない)。
        expect(rels).toContain(path.join("components", "atoms", "Avatar.svelte"));
        expect(rels).toContain(path.join("components", "atoms", "Toggle.svelte"));
        // 走査根の 3 区画がそれぞれ 1 本以上ある
        // (どれかが丸ごと読めていない状態を捕まえる)。
        expect(rels.some((r) => r.startsWith(`components${path.sep}`))).toBe(true);
        expect(rels.some((r) => r.startsWith(`pages${path.sep}`))).toBe(true);
        expect(rels.some((r) => r.startsWith(`lib${path.sep}`))).toBe(true);
    });
});

/**
 * 許可語の除去そのものの検査。
 *
 * 除去が素の部分文字列で行われていると、許可語を部分に含む別の書き方
 * (`!rounded-full` / `sm:rounded-full` / `rounded-full/50`) まで一緒に消えて
 * **検出漏れ**になる。許可したのは「アバターとトグルが真に円形であること」だけで、
 * 変種の修飾や重要度の修飾が付いた別の書き方まで許した覚えはない。
 */
describe("allowlist の除去", () => {
    const AVATAR = path.join("components", "atoms", "Avatar.svelte");
    /** 許可一覧に無いファイル (ファイル単位の免罪であることの対照) */
    const BUTTON = path.join("components", "atoms", "Button.svelte");
    /** 「rounded-sm/md/lg 以外の段は禁止」の規則 */
    const ROUNDED_RAMP = THEME_PATTERNS.find(([pattern]) =>
        pattern.test("rounded-full"),
    )?.[0];

    it("許可一覧に載せたファイルの素のトークンは除去される", () => {
        expect(stripAllowlisted(AVATAR, 'class="rounded-full"')).not.toContain(
            "rounded-full",
        );
    });

    it.each([
        ["重要度の修飾", 'class="!rounded-full"', "!rounded-full"],
        ["変種の修飾", 'class="sm:rounded-full"', "sm:rounded-full"],
        ["不透明度の指定", 'class="rounded-full/50"', "rounded-full/50"],
    ])("負の対照: %s が付いた形は免罪しない", (_label, input, token) => {
        const stripped = stripAllowlisted(AVATAR, input);

        expect(stripped).toContain(token);
        expect(ROUNDED_RAMP).toBeDefined();
        expect(ROUNDED_RAMP?.test(stripped)).toBe(true);
    });

    it("許可一覧に無いファイルでは除去しない", () => {
        expect(stripAllowlisted(BUTTON, 'class="rounded-full"')).toContain(
            "rounded-full",
        );
    });

    it("隣り合うトークンは除去で連結しない", () => {
        const stripped = stripAllowlisted(
            AVATAR,
            'class="rounded-lg rounded-full shadow-lg"',
        );

        expect(stripped).toContain("rounded-lg");
        expect(stripped).toContain("shadow-lg");
        expect(stripped).not.toContain("rounded-full");
        expect(stripped).not.toContain("rounded-lgshadow-lg");
    });

    it("引用符・改行・角括弧に隣接した形も除去される", () => {
        const stripped = stripAllowlisted(
            AVATAR,
            ['class="rounded-full"', "class='rounded-full'", "class={[\n'rounded-full',\n]}"].join(
                "\n",
            ),
        );

        expect(stripped).not.toContain("rounded-full");
    });

    it("許可一覧の全エントリが単一の class トークンとして成立している", () => {
        const dead = FILE_SCOPED_ALLOWLIST.flatMap((entry) =>
            entry.patterns
                .filter((pattern) => !isSingleClassToken(pattern))
                .map((pattern) => `${entry.file}: "${pattern}"`),
        );

        expect(dead).toEqual([]);
    });
});
