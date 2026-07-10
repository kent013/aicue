import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * Lucide 以外の inline SVG 直書きを機械統制する。
 *
 * 通常のアイコンは `@lucide/svelte` の <Icon> component を使う（import 経由のため本検知対象外）。
 *
 * file-scoped allowlist は「DOM 要素では描けないデータ可視化（チャート / 座標系オーバーレイ等）」に
 * 限定し、テンプレートは 0 件で出荷する。新規に inline SVG を足したい場合は、本当に SVG が必須か
 * （Lucide / DOM で代替不可か）を検討し、正当なら allowlist へ理由付きで追加する。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/** inline SVG 許可ファイル（resources/js からの相対パス）。DOM 不可のデータ可視化のみ登録可。 */
const SVG_INLINE_ALLOWLIST: readonly string[] = [] as const;

/**
 * 例外: Lucide が提供しないブランド/SSO ロゴ (GitHub/Google 等) を内包する専用コンポーネント置き場。
 * official SVG の内包のみ許可 (ページ/汎用コンポーネントへの直書きは引き続き禁止)。
 * ディレクトリ単位の許可とし、新規ブランドアイコン追加で allowlist の更新を不要にする。
 */
const SVG_INLINE_ALLOWLIST_DIR_PREFIXES: readonly string[] = [
  "components/atoms/icons/",
] as const;

const SVG_INLINE_PATTERN = /<svg[\s>]/;

const listSvelteFiles = async (dir: string): Promise<string[]> => {
  const entries = await fs.readdir(dir, {
    recursive: true,
    withFileTypes: true,
  });
  const files: string[] = [];
  for (const entry of entries) {
    if (!entry.isFile()) continue;
    if (path.extname(entry.name) !== ".svelte") continue;
    const parent =
      (entry as unknown as { parentPath?: string }).parentPath ?? dir;
    files.push(path.join(parent, entry.name));
  }
  return files;
};

describe("inline SVG allowlist", () => {
  it("resources/js 配下の svelte で inline SVG 直書きは allowlist 登録分のみ", async () => {
    const files = await listSvelteFiles(JS_ROOT);

    const offenders: string[] = [];
    for (const file of files) {
      const content = await fs.readFile(file, "utf8");
      if (!SVG_INLINE_PATTERN.test(content)) continue;

      const rel = path.relative(JS_ROOT, file).split(path.sep).join("/");
      const inAllowedDir = SVG_INLINE_ALLOWLIST_DIR_PREFIXES.some((prefix) =>
        rel.startsWith(prefix),
      );
      if (!SVG_INLINE_ALLOWLIST.includes(rel) && !inAllowedDir) {
        offenders.push(rel);
      }
    }

    expect(
      offenders,
      `Lucide 以外の inline SVG 直書きが見つかりました（@lucide/svelte を使うか、DOM 不可の正当な可視化なら allowlist へ理由付きで追加）:\n${offenders.join("\n")}`,
    ).toEqual([]);
  });

  it("allowlist のエントリは実在し、かつ実際に inline SVG を含む（陳腐化検知）", async () => {
    const stale: string[] = [];
    for (const rel of SVG_INLINE_ALLOWLIST) {
      const abs = path.join(JS_ROOT, rel);
      try {
        const content = await fs.readFile(abs, "utf8");
        if (!SVG_INLINE_PATTERN.test(content)) {
          stale.push(`${rel} (inline SVG を含まない → allowlist から削除可)`);
        }
      } catch {
        stale.push(`${rel} (ファイルが存在しない)`);
      }
    }

    expect(stale, `allowlist が陳腐化しています:\n${stale.join("\n")}`).toEqual(
      [],
    );
  });
});
