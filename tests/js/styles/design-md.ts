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
import { scanMarkdownLines } from "./markdown-lines";

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

/**
 * DESIGN.md の本文から §Components の `###` 節名を取り出す。
 *
 * **S9 が新設した共通 Markdown 行走査 (`scanMarkdownLines`) を共有する** —
 * 独立した弱い解析器を増やさない (正典 i21)。単純な見出し正規表現だと、囲みコードの中に
 * `### 部品名` を置いて「文書化済み」に見せられ、**双方向一致という中心の保証を
 * 直接迂回できる**。
 *
 * 契約 5 条 (いずれも固定検体で裏取りする):
 *   1. `## Components` は**ちょうど 1 節**であること (0 件も 2 件も例外)。
 *      判定は**行頭から始まる有効な ATX 見出し**に限る (字下げした見出しは受理しない —
 *      受理すると字下げコードへ退避させて双方向一致を迂回できる)
 *   2. HTML コメントと囲みコードの中の見出しは**数えない**
 *   3. `###` だけを対象にし、`####` 以降は数えない
 *   4. 同名の節が 2 つあれば**例外**
 *   5. Markdown 走査の診断 (未終端コメント / 未終端 fence / container fence /
 *      未対応 fence) が 1 件でもあれば**解析失敗** (正典 i20)
 *
 * **本関数の呼び出し側 (`component-doc-parity.test.ts`) が DESIGN.md 側の
 * Markdown 診断の消費先である**。
 */
export function parseDesignComponentSections(source: string): readonly string[] {
    const scan = scanMarkdownLines(source);
    if (scan.diagnostics.length > 0) {
        const shown = scan.diagnostics.map((d) => `${d.line}:${d.reason}`).join(", ");
        throw new Error(`DESIGN.md の Markdown 走査が失敗した: ${shown}`);
    }

    const lines = scan.renderedLines;
    // ★`trim()` で探さない — 字下げした行を見出しとして受理すると、
    //   `## Components` を字下げコードへ退避させて双方向一致を迂回できる (fail-open)。
    //   有効な ATX 見出し (行頭から始まる `## Components`) だけを受理する。
    const heads = lines.flatMap((line, index) => (line === "## Components" ? [index] : []));
    if (heads.length !== 1) {
        throw new Error(`DESIGN.md の "## Components" が 1 節でない (実際 ${heads.length} 件)`);
    }

    const sections: string[] = [];
    for (const line of lines.slice(heads[0] + 1)) {
        if (/^#{1,2}\s/.test(line)) break;
        const matched = line.match(/^### (.+)$/);
        if (matched === null) continue;
        const name = matched[1].trim();
        if (sections.includes(name)) throw new Error(`DESIGN.md §Components の節が重複: ${name}`);
        sections.push(name);
    }

    return sections;
}

/** 実ファイルの DESIGN.md から §Components の節名を取り出す薄いラッパー。 */
export function designComponentSections(): readonly string[] {
    return parseDesignComponentSections(designMd);
}
