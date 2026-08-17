/**
 * DESIGN.md (canonical source) の frontmatter パーサ — 検査テスト共有。
 *
 * canonical-source-parity (DESIGN.md ⇔ tokens.css の同期) と
 * contrast-invariant (色の可読性) が **同一のパーサ**を使うためのヘルパ。
 * パーサを二重実装すると「片方だけが読める DESIGN.md」という状態を作れてしまう。
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = path.resolve(HERE, "../../../");

const designMd = fs.readFileSync(path.join(REPO_ROOT, "DESIGN.md"), "utf-8");

/** DESIGN.md 冒頭の `---` で囲まれた frontmatter 本文 */
export const frontmatter: string = (() => {
    const m = designMd.match(/^---\n([\s\S]*?)\n---/);
    if (!m) throw new Error("DESIGN.md frontmatter not found");
    return m[1];
})();

/** frontmatter `colors:` → `{ トークン名 → "#rrggbb" (小文字) }` */
export function designColors(): Map<string, string> {
    const section = frontmatter.match(/^colors:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md colors section not found");
    const map = new Map<string, string>();
    for (const line of section[1].matchAll(/^ {4}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
        map.set(line[1], line[2].toLowerCase());
    }
    return map;
}

/** frontmatter `rounded:` → `{ 段名 → "Npx" }` */
export function designRounded(): Map<string, string> {
    const section = frontmatter.match(/^rounded:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md rounded section not found");
    const map = new Map<string, string>();
    for (const m of section[1].matchAll(/^ {4}([a-z]+): (\d+px)$/gm)) {
        map.set(m[1], m[2]);
    }
    return map;
}

/** frontmatter `typography.<name>:` → `{ プロパティ名 → 値 }` */
export function designRamp(name: string): Record<string, string> {
    const m = frontmatter.match(new RegExp(`^ {4}${name}:\\n((?: {8}\\S[^\\n]*\\n)+)`, "m"));
    if (!m) throw new Error(`DESIGN.md typography ramp not found: ${name}`);
    const props: Record<string, string> = {};
    for (const line of m[1].matchAll(/^ {8}([a-zA-Z]+): "?([^"\n]+)"?$/gm)) {
        props[line[1]] = line[2];
    }
    return props;
}

/**
 * frontmatter の**最上位の節名**を宣言順で返す。
 *
 * 「どの節がどの検査の担当か」を既定拒否で宣言するための入力
 * (tests/js/styles/inventory.ts の FRONTMATTER_SECTION_OWNERS)。
 * 入れ子の子キー (typography.display 等) は含めない — 担当の宣言は節の粒度で行う。
 *
 * 保証範囲: 行頭から始まるキーだけを最上位として拾う。frontmatter の書式が変わったときは
 * 抽出結果が変わり、担当宣言との集合一致で気付ける**ことが多い**が、
 * 別の最上位らしい文字列を拾う形の誤解析まで防げるわけではない。
 */
export function designFrontmatterSections(): readonly string[] {
    const sections: string[] = [];
    for (const m of frontmatter.matchAll(/^([a-zA-Z][a-zA-Z0-9-]*):/gm)) {
        sections.push(m[1]);
    }
    return sections;
}

/**
 * frontmatter `typography:` の**子キー** (ramp 名) を宣言順で返す。
 *
 * TYPOGRAPHY_RAMPS (検査側の母集団) と集合一致させるための入力。
 * これが無いと、DESIGN.md に ramp を足しても検査側の固定配列に入らず見逃す。
 */
export function designTypographyNames(): readonly string[] {
    const section = frontmatter.match(/^typography:\n((?: {4}\S[^\n]*\n| {8}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md typography section not found");
    const names: string[] = [];
    for (const m of section[1].matchAll(/^ {4}([a-zA-Z][a-zA-Z0-9-]*):$/gm)) {
        names.push(m[1]);
    }
    return names;
}
