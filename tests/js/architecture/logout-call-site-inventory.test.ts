import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * ログアウト導線が Inertia visit 一本であることを deny-by-default で固定する。
 *
 * 経路 C (Inertia history 暗号化 + ログアウト時の履歴鍵破棄。bug-hunt F-4-01) の保証は
 * 「clearHistory: true を含む Inertia page をクライアントが適用すること」に乗っている。
 * JSON 204 で完結する logout (fetch/axios) を足すと、鍵が消えないまま画面が残り、
 * ブラウザバックで PII が復元されうる。
 *
 * 新しいログアウト導線を足したい場合は、それが Inertia visit (router.post) であることを
 * 確認した上で inventory に登録すること。docs/supported-browsers.md の経路 C の記述も更新する。
 *
 * 既知の限界: 検出は **文字列リテラル `"/logout"`** に限定される。将来 `route("logout")` の
 * ような名前解決ヘルパを導入すると検出外になるため、その際は本テストのパターンも同時に更新する。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/**
 * `/logout` を参照してよいファイル (resources/js からの相対パス)。
 * 現状 2 箇所あり、いずれも router.post = Inertia visit
 * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線)。
 */
const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
  "components/templates/AppLayout.svelte",
  "pages/Auth/VerifyEmail.svelte",
] as const;

const LOGOUT_PATH_PATTERN = /["'`]\/logout["'`]/;
/** 非 Inertia 経路 (これが同一ファイルにあると 204 完結の logout になりうる)。 */
const NON_INERTIA_CLIENT_PATTERN = /\b(fetch|axios)\s*\(/;

const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;

/** resources/js 配下の .svelte / .ts を再帰列挙する (svg-inline-allowlist.test.ts と同じ様式)。 */
const listSourceFiles = async (dir: string): Promise<string[]> => {
  const entries = await fs.readdir(dir, {
    recursive: true,
    withFileTypes: true,
  });
  const files: string[] = [];
  for (const entry of entries) {
    if (!entry.isFile()) continue;
    if (!SOURCE_EXTENSIONS.includes(path.extname(entry.name))) continue;
    const parent =
      (entry as unknown as { parentPath?: string }).parentPath ?? dir;
    files.push(path.join(parent, entry.name));
  }
  return files;
};

describe("logout call site inventory", () => {
  it("resources/js 配下で /logout を叩くのは inventory 登録分のみ", async () => {
    const files = await listSourceFiles(JS_ROOT);

    const offenders: string[] = [];
    for (const file of files) {
      const content = await fs.readFile(file, "utf8");
      if (!LOGOUT_PATH_PATTERN.test(content)) continue;

      const rel = path.relative(JS_ROOT, file).split(path.sep).join("/");
      if (!LOGOUT_CALL_SITE_INVENTORY.includes(rel)) offenders.push(rel);
    }

    expect(
      offenders,
      `未登録のログアウト導線が見つかりました。Inertia visit (router.post) であることを確認して inventory へ登録し、docs/supported-browsers.md の経路 C も更新してください:\n${offenders.join("\n")}`,
    ).toEqual([]);
  });

  it("inventory 登録ファイルは Inertia visit (router.post) でログアウトする", async () => {
    for (const rel of LOGOUT_CALL_SITE_INVENTORY) {
      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");

      // Inertia visit であること (= 着地の Inertia page を適用し clearHistory が効く)
      expect(content, `${rel} が router.post('/logout') を使っていない`).toMatch(
        /router\.post\(\s*["'`]\/logout["'`]/,
      );
      // 同一ファイルに fetch/axios による非 Inertia 経路を持ち込まない
      expect(
        NON_INERTIA_CLIENT_PATTERN.test(content),
        `${rel} に fetch/axios がある。logout が 204 完結の非 Inertia 経路になっていないか確認すること`,
      ).toBe(false);
    }
  });
});
