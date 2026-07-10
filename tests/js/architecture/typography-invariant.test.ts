import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * components / pages 配下の typography を DESIGN.md ramp に固定する。
 *
 * テンプレートの DESIGN.md (§Typography) は named ramp utility
 * (`text-display` / `text-h1` / `text-h2` / `text-h3` / `text-body` / `text-caption`) を
 * canonical としており、arbitrary 値は sanctioned しない。本ゲートは ramp 外への drift を防ぐ:
 *   1. arbitrary font-family / font-weight 禁止 (`font-[...]`)。ramp が family/weight を内包する。
 *      (ds-purity の font- パターンは `font-[` を捕捉しないため、本ゲートが gap を塞ぐ)
 *   2. arbitrary text size (`text-[Npx]`) は sanctioned 集合のみ許可。テンプレートは空集合で出荷し、
 *      アプリが DESIGN.md の ramp 改定と同一 PR でのみ追加できる (勝手な任意サイズ追加を防ぐ)。
 *
 * raw の named size (`text-sm` 等) / weight (`font-bold` 等) は ds-purity (THEME_PATTERNS) が統制する。
 */

const REPO_RESOURCES_JS = path.resolve(__dirname, "../../../resources/js");
const SCAN_DIRS = [
    path.join(REPO_RESOURCES_JS, "components"),
    path.join(REPO_RESOURCES_JS, "pages"),
];
const SVELTE_AND_TS: ReadonlySet<string> = new Set([".svelte", ".ts"]);

/**
 * sanctioned arbitrary text size (px)。テンプレートは空集合で出荷する。
 * 追加する場合は DESIGN.md §Typography の ramp 改定と同一 PR で行うこと。
 */
const SANCTIONED_ARBITRARY_TEXT_PX: ReadonlySet<string> = new Set([]);

const listFiles = async (dir: string): Promise<string[]> => {
    try {
        await fs.access(dir);
    } catch {
        return [];
    }
    const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
    const files: string[] = [];
    for (const entry of entries) {
        if (!entry.isFile()) continue;
        if (!SVELTE_AND_TS.has(path.extname(entry.name))) continue;
        const parent = (entry as unknown as { parentPath?: string }).parentPath ?? dir;
        files.push(path.join(parent, entry.name));
    }
    return files;
};

const listAllFiles = async (): Promise<string[]> =>
    (await Promise.all(SCAN_DIRS.map((d) => listFiles(d)))).flat();

const ARBITRARY_FONT = /\bfont-\[[^\]]+\]/g;
const ARBITRARY_TEXT_PX = /\btext-\[(\d+)px\]/g;

describe("typography invariant (DESIGN.md ramp 固定)", () => {
    it("arbitrary font-family / font-weight (font-[...]) を使っていない", async () => {
        const files = await listAllFiles();
        const violations: string[] = [];
        for (const file of files) {
            const content = await fs.readFile(file, "utf-8");
            for (const m of content.matchAll(ARBITRARY_FONT)) {
                violations.push(
                    `${path.relative(REPO_RESOURCES_JS, file)}: ${m[0]} (ramp utility が family/weight を内包する)`,
                );
            }
        }
        expect(violations, violations.join("\n")).toEqual([]);
    });

    it("arbitrary text size (text-[Npx]) は sanctioned 集合 (テンプレートは空) のみ", async () => {
        const files = await listAllFiles();
        const violations: string[] = [];
        for (const file of files) {
            const content = await fs.readFile(file, "utf-8");
            for (const m of content.matchAll(ARBITRARY_TEXT_PX)) {
                if (!SANCTIONED_ARBITRARY_TEXT_PX.has(m[1])) {
                    violations.push(
                        `${path.relative(REPO_RESOURCES_JS, file)}: ${m[0]} は sanctioned 外 ` +
                            `(typography ramp text-{display,h1,h2,h3,body,caption} を使う)`,
                    );
                }
            }
        }
        expect(violations, violations.join("\n")).toEqual([]);
    });

    describe("rule pattern self-check", () => {
        it("arbitrary font は検知し、font-mono/font-medium は誤検知しない", () => {
            expect('class="font-[\'Foo\']"').toMatch(/\bfont-\[[^\]]+\]/);
            expect('class="font-mono font-medium"').not.toMatch(/\bfont-\[[^\]]+\]/);
        });
        it("text-[Npx] を検知 (例: text-[13px])", () => {
            const off = [...'class="text-[13px]"'.matchAll(ARBITRARY_TEXT_PX)].filter(
                (m) => !SANCTIONED_ARBITRARY_TEXT_PX.has(m[1]),
            );
            expect(off.length).toBe(1);
        });
        it("ramp utility (text-caption 等) は誤検知しない", () => {
            const off = [...'class="text-caption text-body"'.matchAll(ARBITRARY_TEXT_PX)];
            expect(off.length).toBe(0);
        });
    });
});
