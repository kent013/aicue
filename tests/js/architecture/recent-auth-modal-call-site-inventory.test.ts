import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * RecentAuthModal の props 契約を deny-by-default で固定する。
 *
 * T106 で `passkeyAvailable` を optional prop として足した結果、6 呼び出し中 5 箇所が
 * 未配線のまま出荷され、passkey-only ユーザーが 5 画面で「手段 0 + 事実に反する文言」で
 * 詰んだ (監査 F-1)。pnpm typecheck は `tsc --noEmit` で .svelte テンプレートを型検査しないため、
 * 必須 prop 化だけでは配線漏れを止められない。ここが唯一の機械的強制点になる。
 *
 * 新しい呼び出し側を足す場合は、`withRecentAuth` の onStale で受けた status を
 * `recentAuthStatus` に格納し、`status={recentAuthStatus}` で渡した上で inventory に登録すること。
 *
 * 既知の限界: 検出は文字列パターンに依存する。JSX 的な spread ({...props}) や
 * 動的コンポーネント経由の描画は検出できない。導入する際は本テストも同時に更新すること。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/** RecentAuthModal を使ってよいファイル (resources/js からの相対パス) */
const RECENT_AUTH_MODAL_CALL_SITES: readonly string[] = [
  "pages/Settings/Index.svelte",
  "pages/Settings/Security.svelte",
  "pages/Organizations/Settings.svelte",
  "pages/Organizations/ApiKeys/Index.svelte",
  "pages/Organizations/ApiKeys/Sessions.svelte",
  "pages/Admin/Users.svelte",
] as const;

/**
 * 主検出は **タグ出現**。alias import (`@/components/...`) だけを見ると
 * 相対 import で bypass できるため、import 形に依存しない検出を主にする。
 */
const TAG_PATTERN = /<RecentAuthModal\b[^>]*>/g;
/** 補助検出 (import だけして別名で描画する形も未登録として拾う) */
const IMPORT_PATTERN = /import\s+\w+\s+from\s+["'][^"']*RecentAuthModal\.svelte["']/;
/** status は識別子まで固定する (任意式・undefined・即席オブジェクトを許さない) */
const STATUS_PROP_PATTERN = /status=\{recentAuthStatus\}/;
/** 契約統合前の旧 prop (後方互換の並走を残さない) */
const LEGACY_PROPS: readonly string[] = [
  "passwordSet=",
  "availableProviders=",
  "canSatisfy=",
  "passkeyAvailable=",
] as const;
/** status の出所を /recent-auth/status 1 本に固定する */
const WITH_RECENT_AUTH_PATTERN = /withRecentAuth/;
/** onStale の引数を recentAuthStatus に格納していること (存在確認だけでは不十分) */
const ON_STALE_ASSIGNMENT_PATTERN =
  /onStale:\s*\(status\)\s*=>\s*\{[^}]*recentAuthStatus\s*=\s*status/s;

const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;

/** resources/js 配下の .svelte / .ts を再帰列挙する (logout-call-site-inventory と同じ様式)。 */
const listSourceFiles = async (dir: string): Promise<string[]> => {
  const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
  const files: string[] = [];
  for (const entry of entries) {
    if (!entry.isFile()) continue;
    if (!SOURCE_EXTENSIONS.includes(path.extname(entry.name))) continue;
    const parent = (entry as unknown as { parentPath?: string }).parentPath ?? dir;
    files.push(path.join(parent, entry.name));
  }
  return files;
};

describe("recent-auth modal call site inventory", () => {
  it("RecentAuthModal を描画/import するのは inventory 登録分のみ", async () => {
    const files = await listSourceFiles(JS_ROOT);
    const offenders: string[] = [];
    for (const file of files) {
      const content = await fs.readFile(file, "utf8");
      // タグ出現 (主) と import (補助) の和で検出する (import 形に依存しない)
      const hasTag = TAG_PATTERN.test(content);
      TAG_PATTERN.lastIndex = 0; // /g の状態を持ち越さない
      if (!hasTag && !IMPORT_PATTERN.test(content)) continue;
      const rel = path.relative(JS_ROOT, file).split(path.sep).join("/");
      if (!RECENT_AUTH_MODAL_CALL_SITES.includes(rel)) offenders.push(rel);
    }
    expect(
      offenders,
      `未登録の RecentAuthModal 呼び出しが見つかりました。status={recentAuthStatus} で渡していることを確認して inventory へ登録してください:\n${offenders.join("\n")}`,
    ).toEqual([]);
  });

  it("全呼び出しが status={recentAuthStatus} を渡し、旧 prop を渡さない", async () => {
    for (const rel of RECENT_AUTH_MODAL_CALL_SITES) {
      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");
      const tags = content.match(TAG_PATTERN) ?? [];
      expect(tags.length, `${rel} に <RecentAuthModal> が無い`).toBeGreaterThan(0);
      for (const tag of tags) {
        expect(tag, `${rel} が status={recentAuthStatus} を渡していない`).toMatch(
          STATUS_PROP_PATTERN,
        );
        for (const legacy of LEGACY_PROPS) {
          expect(
            tag.includes(legacy),
            `${rel} が旧 prop ${legacy} を渡している (契約は status 1 本)`,
          ).toBe(false);
        }
      }
    }
  });

  it("status の出所は withRecentAuth の onStale に固定される", async () => {
    for (const rel of RECENT_AUTH_MODAL_CALL_SITES) {
      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");
      expect(content, `${rel} が withRecentAuth を使っていない`).toMatch(
        WITH_RECENT_AUTH_PATTERN,
      );
      expect(
        content,
        `${rel} が onStale で受けた status を recentAuthStatus に格納していない (画面ごとの独自判定を作らない)`,
      ).toMatch(ON_STALE_ASSIGNMENT_PATTERN);
    }
  });
});
