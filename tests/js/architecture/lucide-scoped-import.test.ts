import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * scoped lucide import の恒久ガード。
 *
 * アイコンは Lucide のみ (DESIGN.md) という規約に連なるガードとして:
 * (1) アイコンは scoped 後継 `@lucide/svelte` のみを使い、旧 unscoped パッケージ `lucide-svelte`
 *     の再 import（named/default・side-effect・dynamic）を全廃で固定する。
 * (2) `@lucide/svelte@^1.17` で drop / rename された旧識別子（AlertCircle / CheckCircle / XCircle /
 *     BarChart3 等）を `@lucide/svelte` から import する退行を deny-list で禁止する。
 *
 * 1.16→1.17 で旧 alias が撤去されたため、旧識別子は新名へ rename する
 * (CheckCircle→CircleCheck / XCircle→CircleX / AlertTriangle→TriangleAlert /
 * BarChart3→ChartColumn / HelpCircle→CircleHelp / Home→House 等)。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

// 旧パッケージ参照を 3 形式すべてで検出:
//  (1) named/default: `... from 'lucide-svelte'`  (2) side-effect: `import 'lucide-svelte'`
//  (3) dynamic: `import(/* comment */ 'lucide-svelte')`
// `@lucide/svelte`（scoped）には誤マッチしないよう、直前が `@lucide/` でない `lucide-svelte` のみ拾う。
const OLD_PKG_PATTERN =
  /(from\s*['"]lucide-svelte['"]|import\s+['"]lucide-svelte['"]|import\s*\(\s*(?:\/\*[^]*?\*\/\s*)?['"]lucide-svelte['"])/;

// 1.16→1.17 で drop / rename された旧識別子（@lucide/svelte からの誤 import を禁止）。
// deprecated alias の再導入を塞ぐため、未使用のものも含めて列挙する。
const OLD_IDENTIFIERS = [
  "AlertCircle",
  "AlertTriangle",
  "BarChart3",
  "CheckCircle",
  "CheckCircle2",
  "Edit",
  "Filter",
  "HelpCircle",
  "Home",
  "Loader2",
  "PlayCircle",
  "PlusCircle",
  "XCircle",
];

// @lucide/svelte の named import ブロックを抽出。default+named 併記 + `import type {` も対応。
const SCOPED_NAMED_PATTERN =
  /import\s+(?:type\s+)?(?:[\w$]+\s*,\s*)?\{([^}]*)\}\s*from\s*['"]@lucide\/svelte['"]/g;

const listSourceFiles = async (dir: string): Promise<string[]> => {
  const out: string[] = [];
  for (const entry of await fs.readdir(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...(await listSourceFiles(p)));
    } else if (/\.(svelte|ts)$/.test(entry.name)) {
      out.push(p);
    }
  }
  return out;
};

describe("lucide scoped import 恒久ガード", () => {
  it("走査ルート健全性（resources/js のソースを検出）", async () => {
    const files = await listSourceFiles(JS_ROOT);
    expect(files.length).toBeGreaterThan(0);
  });

  it("旧 unscoped パッケージ lucide-svelte の参照は全廃（from/side-effect/dynamic）", async () => {
    const files = await listSourceFiles(JS_ROOT);
    const violations: string[] = [];

    for (const file of files) {
      const src = await fs.readFile(file, "utf8");
      // `@lucide/svelte` を除いた純粋な `lucide-svelte` 参照のみ検出する。
      const stripped = src.replace(/@lucide\/svelte/g, "@lucide/__scoped__");
      if (OLD_PKG_PATTERN.test(stripped)) {
        violations.push(path.relative(JS_ROOT, file));
      }
    }

    expect(
      violations,
      `旧 lucide-svelte 参照が残存（@lucide/svelte へ移行すること）: ${violations.join(", ")}`,
    ).toEqual([]);
  });

  it("1.17 で drop された旧アイコン識別子を @lucide/svelte から import しない（type/別名先頭名も検査）", async () => {
    const files = await listSourceFiles(JS_ROOT);
    const violations: string[] = [];

    for (const file of files) {
      const src = await fs.readFile(file, "utf8");
      let match: RegExpExecArray | null;
      SCOPED_NAMED_PATTERN.lastIndex = 0;
      while ((match = SCOPED_NAMED_PATTERN.exec(src)) !== null) {
        const names = match[1]
          .split(",")
          .map((s) => s.trim().replace(/^type\s+/, "").split(/\s+as\s+/)[0].trim())
          .filter(Boolean);
        for (const name of names) {
          if (OLD_IDENTIFIERS.includes(name)) {
            violations.push(`${path.relative(JS_ROOT, file)}: ${name}`);
          }
        }
      }
    }

    expect(
      violations,
      `1.17 で drop 済の旧識別子 import が残存（新名へ rename すること）: ${violations.join(", ")}`,
    ).toEqual([]);
  });
});
