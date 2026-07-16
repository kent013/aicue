import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from "url";

/*
 * deprecated-imports — 廃止したコンポーネントが resources/js のどこからも再導入されないことを保証する。
 * T071 で AdminMenuNav(独自二次左メニュー)を退役。別層からの再導入も防ぐため resources/js 全体を走査する。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const RESOURCES_JS = path.resolve(HERE, "../../../resources/js");

/** 廃止 import: { spec, reason }。追加時は理由必須。 */
const DEPRECATED: ReadonlyArray<{ spec: string; reason: string }> = [
    {
        spec: "@/components/features/admin/AdminMenuNav.svelte",
        reason: "T071: aigenba に無い独自二次左メニュー。標準サイドバー nav + プロジェクト文脈導線へ移行し退役。",
    },
];

async function sourceFiles(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && /\.(svelte|ts)$/.test(e.name)) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

describe("architecture/deprecated-imports", () => {
    it("廃止エントリは理由必須", () => {
        for (const d of DEPRECATED) expect(d.reason.trim(), d.spec).not.toBe("");
    });

    it("resources/js は廃止コンポーネントを import しない", async () => {
        const files = await sourceFiles(RESOURCES_JS);
        const violations: string[] = [];
        for (const file of files) {
            const src = await fs.readFile(file, "utf8");
            for (const d of DEPRECATED) {
                if (src.includes(d.spec)) {
                    violations.push(`${path.relative(RESOURCES_JS, file)} → ${d.spec}`);
                }
            }
        }
        expect(violations, `廃止 import 検出:\n  - ${violations.join("\n  - ")}`).toEqual([]);
    });
});
