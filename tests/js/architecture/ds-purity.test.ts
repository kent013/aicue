import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import {
    THEME_PATTERNS,
    UNIVERSAL_PATTERNS,
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
});
