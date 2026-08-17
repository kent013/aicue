# impl-review Round 2

Round 1 の指摘への対応が済んだので再レビューを依頼する。使命・禁止事項・思考原則は Round 1 と同じである。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex (gpt-5.6-sol / reasoning=high) の全体判定は **CHANGES_REQUESTED**。
指摘は 3 件の Critical と 5 件の Warning、および記録の不足 2 件。

## [Critical] fenced code の終端判定が CommonMark と一致しない (4 空白字下げの偽の終端で閉じる)

- 判断: **対応する**
- 根拠: 指摘のとおりの fail-open である。区間の途中に 4 空白字下げの ``` を置くと、
  そこで閉じたことになり後続の隠したい本文が「描画される本文」として通る。
  追補が塞いだと主張している穴そのものなので、主張と実装のどちらを直すかではなく実装を直す。
- 対応内容: 開始・終了とも字下げを 3 空白までに限定し (`FENCE_OPEN` / `FENCE_CLOSE`)、
  終了は後続が空白のみであることを要求した。原文のインデントを保つため `trim()` をやめた。
  fixture に「3 空白は fence として扱う」「4 空白の偽の終端では閉じない」の 2 例を足した。

## [Critical] 「同期契約の本文が改変されていないか」を保証していない (非空しか見ていない)

- 判断: **対応する**
- 根拠: 家系の追従判定の第 2 条は「空・改変の両方」を求めている。非空だけを見る検査では
  後半を満たせず、追補と D27 の主張が実装より広い。設計は「散文は見ない」としていたが、
  それは**言い回しを固定しない**という意図であって、契約の一文が消えても緑でよいという
  意味ではないと解釈した。
- 対応内容: `SECTION_CONTRACT_PHRASES` (節ごとの規範の最小断片) を新設し、
  描画される本文に含まれることを検査した。宣言した節と `REQUIRED_SECTIONS` の集合一致も
  併せて固定し、片方だけの節を作れないようにした。最小断片は節あたり 1〜2 文に絞り、
  「文言を直すときは同じ PR で最小断片も直す」ことを本文と docblock に明記した。

## [Critical] themeVariables がルート直下でない `@layer theme` も採る

- 判断: **対応する**
- 根拠: 条件つき at-rule の中のテーマ層は、生成 CSS に文字列としては現れても
  条件が成立しなければ画面には効かない。走査範囲を絞ることが本ファイルの検出力そのもの
  だと自分で書いておきながら、絞りが 1 段甘かった。
- 対応内容: `root.walkAtRules("layer", …)` をやめ、**ルートの直接の子**の
  `@layer theme` だけを見るようにした。fixture に
  `@supports (…) { @layer theme { :root, :host { … } } }` の負の例を足した。

## [Warning] `selectors.every(...)` が `:root` 単独・`:host` 単独も受理する

- 判断: **見送る**
- 根拠: `:root, :host` という綴りは Tailwind v4 の出力形であって、本アプリが守りたい
  不変条件ではない。ここを完全一致にすると、テーマ変数の到達性という主眼と関係のない
  vendor の実装詳細に検査を結びつけることになる (版が上がるたびに赤くなる)。
  `@theme` の破損は A の**値**検査が検出することを R2 の実測で確認済みである。
- 対応内容: なし (docblock の「Tailwind の版が上がったら読み方を直す」方針が既にこの立場を書いている)。

## [Warning] 不成立の `@supports` の中にしかない utility でも通る

- 判断: **対応する**
- 根拠: 「生成されている」ことの検査が「文字列として在る」ことに退化していた。
- 対応内容: `rulesWithSelector()` が**条件つき at-rule の祖先を持つ規則を数えない**ようにした
  (`hasConditionalAncestor()`。`@layer` は層の指定であって条件ではないので通す)。
  fixture に負の例を足した。

## [Warning] hover 宣言が `@media (hover: none)` へ移っても通る

- 判断: **対応する**
- 根拠: テスト名が「hover 時の背景色になる」と言っている以上、打ち消す条件の下でだけ
  成り立つ宣言を数えるのは名前と実体の食い違いである。
- 対応内容: `collectDeclarations()` が通った条件つき at-rule の条件文を `conditions` として返し、
  D が `ALLOWED_HOVER_CONDITIONS` (現在は `(hover: hover)` の 1 件) と突き合わせるようにした。
  負の fixture で `(hover: none)` が条件として現れ、許容一覧に無いことを固定した。

## [Warning] HTML コメント除去が行内コードの文脈を見ない

- 判断: **見送る (保証の書き方だけ直す)**
- 根拠: Markdown パーサを持ち込むと依存が増え、本バッチの主眼 (トークンの到達性) から遠い。
  誤って潰す方向の誤りであり、fail-open ではない (行内コードとして書いた `<!-- … -->` は
  最小断片に含めない限り実害が無い)。
- 対応内容: docblock の「保証しないもの」に、行内コードの文脈を見ないことを明記した。

## [Warning] `docs/design-system.md` と追補・D27 の主張が実装より広い

- 判断: **対応する**
- 根拠: 実装を直したうえで、なお残る範囲 (最小断片の外の説明・字下げコードブロック・
  HTML 要素による非表示) を正確に書く必要がある。
- 対応内容: `docs/design-system.md` の該当段落を最小断片の話に書き換え、
  D27 の比較表と「保証しないもの」を実装に合わせて直し、追補に本ラウンドの変更を追記した。

## [追加確認が必要] 負のコントロールが感度確認に無い

- 判断: **対応する (ただし置き場所は fixture)**
- 根拠: 指摘の 4 つのうち 3 つは「壊れた入力を読ませたときのヘルパの挙動」であり、
  リポジトリのファイルを一時的に壊す感度確認より、固定 fixture で恒久的に留める方が強い
  (毎回走り、後から緩めると赤くなる)。残る 1 つ (`:root` / `:host` 単独) は上記のとおり見送った。
- 対応内容: fixture に 4 空白の偽の終端 / 条件つき at-rule の中のテーマ層 /
  条件つき at-rule の中の utility / 打ち消す条件の hover / 最小断片の退避 の 5 例を足した。
  `red-verification.md` にもこの置き場所の判断を書いた。

## [未完了] 検証レーンが実行中

- 判断: **対応する**
- 根拠: そのとおりで、`composer test` / `pnpm test` / `pnpm test:packages` の完了をもって
  Definition of Done とする。
- 対応内容: 修正後に 3 レーンを流し直し、結果を `verification-results.md` に確定値で書く。

## 修正後の全文 (該当 2 ファイル)

### tests/js/styles/design-system-docs.test.ts

```ts
import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT } from "./design-md";

/*
 * design-system-docs — docs/design-system.md の**構造**が壊れていないことを検査する。
 *
 * 【見るもの】節の実在と本文の非空 / 節ごとの**規範の最小断片** / 表のセルに並ぶパスの実在 /
 *   検査目録の双方向の集合一致
 * 【見ないもの】散文そのもの。下の SECTION_CONTRACT_PHRASES に挙げた最小断片**以外**の
 *   言い回しは検査しない (文章を良くする PR を止めないため)
 *
 * 【描画されない領域を先に落とす理由】
 *   Markdown の本文には「ファイルには書かれているが読者には表示されない」領域がある
 *   (HTML コメント / fenced code)。契約の本文をそこへ移すだけで「節はあるし本文も空でない」
 *   状態を作れてしまうため、検査の前に該当行を空行へ潰す (fail-open を塞ぐ)。
 *   潰しの判定は CommonMark の fence 規則に合わせる — **開始も終了も字下げは 3 空白まで**で、
 *   4 空白以上の `` ``` `` は fence ではない (これを fence 扱いにすると、
 *   区間の途中に偽の終端を置いて後続を「描画される本文」に見せかけられる)。
 *
 * 【保証しないもの】
 *   - **運用契約の意味が残っていること**。最小断片が本文に在ることまでしか見ておらず、
 *     周りの説明が骨抜きになっていることは検出できない
 *   - **描画されない領域の全種類**。潰すのは HTML コメントと fenced code の 2 つだけで、
 *     4 空白字下げのコードブロックや HTML 要素による非表示は見ていない
 *     (Markdown の文脈依存が強く、誤って本文を潰す方が害が大きいため)。
 *     また HTML コメントの除去は**行内コード (`` ` `` 囲み) の文脈を見ない**ので、
 *     行内コードとして書いた `<!-- … -->` は読者に見えていても潰される
 *     (最小断片をそこへ書かない限り実害は無い)
 *   - **リポジトリ全体のデザイントークン検査の網羅**。自動で母集団に入るのは
 *     `tests/js/styles/` 直下の `*.test.ts` と、下の EXTERNAL_GATE_FILES に明示登録した分だけ。
 *     別の場所へ検査を足しても自動では見つからない
 */

const DOC_PATH = path.join(REPO_ROOT, "docs/design-system.md");

/** 本文を持つことを求める節 (見出し行そのもの)。 */
const REQUIRED_SECTIONS = [
    "## Canonical source の宣言",
    "## トークン変更時の運用契約",
    "## 検査の責務境界",
    "## 新規 domain 色トークン追加の必須条件(4 条件)",
    "## file-scoped allowlist の運用",
] as const;

/**
 * 節ごとの**規範の最小断片**。読者に描画される本文に含まれていることを求める。
 *
 * 「節はあるが中身が別の文に差し替わっている」形を塞ぐための最小限の pin であり、
 * **散文を固定する目的ではない**。ここに並べるのは、消えたらその節が運用契約として
 * 意味を失う一文だけに限る (説明を良くする PR を止めないため)。
 *
 * 家系の追従判定は「同期契約の本文が空・改変されていないか」を求めており、
 * 空だけを見る検査ではその後半を満たせないので、この最小断片で受ける。
 * 文言を直すときは同じ PR でここも直す (それが「契約を変えた」ことの可視化になる)。
 */
const SECTION_CONTRACT_PHRASES: Readonly<Record<string, readonly string[]>> = {
    "## Canonical source の宣言": ["DESIGN.md が唯一の真実"],
    "## トークン変更時の運用契約": ["同一 PR 内で", "片方だけ更新する PR は merge しない"],
    "## 検査の責務境界": ["この表は機械で実体と突き合わせている"],
    "## 新規 domain 色トークン追加の必須条件(4 条件)": ["同一 PR で更新する"],
    "## file-scoped allowlist の運用": ["区切り文字で分割した class トークンとの完全一致"],
};

/**
 * `tests/js/styles/` の外にある対象検査 (明示登録)。
 * 実在を確かめてから母集団へ入れる — 登録したまま消えたファイルを見逃さないため。
 */
const EXTERNAL_GATE_FILES = ["tests/js/architecture/contrast-invariant.test.ts"] as const;

/**
 * 読者に描画されない領域 (HTML コメント / fenced code) を空行へ潰した行配列を返す。
 *
 * 行数は保存する (行番号がずれると節の切り出しがずれるため)。
 *
 * fence の判定は CommonMark に合わせる:
 *   - 開始も終了も**字下げは 3 空白まで**。4 空白以上の `` ``` `` は fence ではない
 *     (緩めると、区間の途中に偽の終端を置いて後続を描画される本文に見せかけられる)
 *   - 終了は**開始と同じ記号**で**開始と同じかそれ以上の長さ**、後続は空白のみ
 *     (`~~~` で開いた区間の中の 3 連バッククォートでは閉じない)
 * 閉じないまま EOF に達したら、そこまでを潰す。
 */
const FENCE_OPEN = /^ {0,3}(`{3,}|~{3,})/;
const FENCE_CLOSE = /^ {0,3}(`{3,}|~{3,})[ \t]*$/;

function renderedLines(doc: string): readonly string[] {
    const out: string[] = [];
    let fence: { readonly char: string; readonly length: number } | null = null;
    let inComment = false;

    for (const raw of doc.split(/\r?\n/)) {
        if (fence !== null) {
            const close = raw.match(FENCE_CLOSE);
            if (close !== null && close[1][0] === fence.char && close[1].length >= fence.length) {
                fence = null;
            }
            out.push("");
            continue;
        }

        let line = raw;
        if (inComment) {
            const end = line.indexOf("-->");
            if (end < 0) {
                out.push("");
                continue;
            }
            // コメントの終端より後ろだけを描画される本文として残す
            line = line.slice(end + 3);
            inComment = false;
        }

        // 同一行に閉じる HTML コメントは繰り返し取り除く
        for (;;) {
            const start = line.indexOf("<!--");
            if (start < 0) break;
            const end = line.indexOf("-->", start + 4);
            if (end < 0) {
                line = line.slice(0, start);
                inComment = true;
                break;
            }
            line = line.slice(0, start) + line.slice(end + 3);
        }

        const open = line.match(FENCE_OPEN);
        if (open !== null) {
            fence = { char: open[1][0], length: open[1].length };
            out.push("");
            continue;
        }

        out.push(line);
    }

    return out;
}

/**
 * 見出しから、次の同レベル以上の見出しまでの本文を返す。
 * `## X` の中の `### Y` は同じ節の本文として残る。
 */
function extractSection(lines: readonly string[], heading: string): readonly string[] {
    const start = lines.indexOf(heading);
    if (start < 0) return [];
    const level = (heading.match(/^#+/) ?? [""])[0].length;
    const rest = lines.slice(start + 1);
    const end = rest.findIndex((line) => new RegExp(`^#{1,${level}}\\s`).test(line));
    return end < 0 ? rest : rest.slice(0, end);
}

/**
 * Markdown 表の指定した列から、最初のバッククォート囲みの文字列を取り出す。
 *
 * 散文に同じ文字列を書いても通ってしまわないよう、**表の行のセル**だけを見る
 * (区切り行とヘッダー行はバッククォートを持たないので自然に落ちる)。
 */
function tableCellLiterals(section: readonly string[], column: number): readonly string[] {
    const literals: string[] = [];
    for (const line of section) {
        const trimmed = line.trim();
        if (!trimmed.startsWith("|")) continue;
        const cells = trimmed.split("|").slice(1, -1);
        const cell = cells[column];
        if (cell === undefined) continue;
        const literal = cell.match(/`([^`]+)`/)?.[1];
        if (literal !== undefined) literals.push(literal);
    }
    return literals;
}

/** 責務境界表に載っていなければならない検査ファイルの母集団。 */
function gateFiles(): readonly string[] {
    const stylesDir = path.join(REPO_ROOT, "tests/js/styles");
    const styles = fs
        .readdirSync(stylesDir)
        .filter((name) => name.endsWith(".test.ts"))
        .map((name) => `tests/js/styles/${name}`);

    for (const external of EXTERNAL_GATE_FILES) {
        // 明示登録したファイルが消えていたらここで落とす (行だけ残る状態を作らせない)。
        expect(
            fs.statSync(path.join(REPO_ROOT, external)).isFile(),
            `${external} が実在しない (EXTERNAL_GATE_FILES の登録が古い)`,
        ).toBe(true);
    }
    return [...styles, ...EXTERNAL_GATE_FILES].sort();
}

let doc: readonly string[];

beforeAll(() => {
    doc = renderedLines(fs.readFileSync(DOC_PATH, "utf-8"));
});

/* ===== ヘルパの仕様固定 (fixture) =====
 *
 * 「描画されない領域を潰す」という性質は本ファイルの検出力そのものなので、
 * 実文書だけを相手にすると「潰しが効いているから緑」なのか
 * 「潰さなくても緑」なのか区別できない。壊れた形を含む小さな文書で仕様を固定する。
 */

const RENDER_FIXTURE = [
    "## 節",
    "描画される本文",
    "<!-- 隠された本文 -->",
    "<!-- 複数行の",
    "隠された本文 -->行末は描画される",
    "```",
    "fenced の中の本文",
    "~~~",
    "```",
    "~~~~",
    "長い記号で開いた区間の中の ```",
    "~~~~",
    "   ```",
    "3 空白までは fence として扱う本文",
    "   ```",
    "```",
    "偽の終端の手前の本文",
    "    ```",
    "偽の終端の後ろの本文",
    "```",
    "<!-- 閉じないコメント",
    "ここも隠れる",
].join("\n");

describe("design-system-docs: 描画されない領域の除去 (fixture)", () => {
    const rendered = renderedLines(RENDER_FIXTURE);

    it("行数を保存する (節の切り出しがずれない)", () => {
        expect(rendered.length).toBe(RENDER_FIXTURE.split("\n").length);
    });

    it("HTML コメント・fenced code の中身が残らない", () => {
        const body = rendered.join("\n");
        expect(body).toContain("描画される本文");
        expect(body).toContain("行末は描画される");
        expect(body).not.toContain("隠された本文");
        expect(body).not.toContain("fenced の中の本文");
        expect(body).not.toContain("長い記号で開いた区間の中の");
        expect(body).not.toContain("3 空白までは fence として扱う本文");
        expect(body).not.toContain("ここも隠れる");
    });

    it("負のコントロール: 4 空白字下げの偽の終端では閉じない", () => {
        // 緩めると「区間の途中に偽の終端を置いて後続を描画される本文に見せかける」
        // 回避口ができる。ここが本ファイルの検出力そのものなので恒久的に固定する。
        const body = rendered.join("\n");
        expect(body).not.toContain("偽の終端の手前の本文");
        expect(body).not.toContain("偽の終端の後ろの本文");
    });

    it("隠れた行だけの節は本文が空とみなされる", () => {
        const onlyHidden = renderedLines(["## 節", "<!-- 隠された -->", "## 次"].join("\n"));
        const body = extractSection(onlyHidden, "## 節");
        expect(body.some((line) => line.trim() !== "")).toBe(false);
    });

    it("最小断片を描画されない領域へ移すと見つからなくなる", () => {
        const hidden = renderedLines(
            ["## 節", "```", "契約の最小断片", "```", "別の可視行", "## 次"].join("\n"),
        );
        const body = extractSection(hidden, "## 節").join("\n");
        expect(body).toContain("別の可視行");
        expect(body).not.toContain("契約の最小断片");
    });
});

describe("design-system-docs: 運用契約の節", () => {
    it.each([...REQUIRED_SECTIONS])("%s が存在し、本文を持つ", (heading) => {
        const body = extractSection(doc, heading);
        expect(body.length, `${heading} が見つからない`).toBeGreaterThan(0);
        expect(
            body.some((line) => line.trim() !== ""),
            `${heading} の本文が空`,
        ).toBe(true);
    });
});

describe("design-system-docs: 節ごとの規範の最小断片", () => {
    it("宣言した節と REQUIRED_SECTIONS が集合一致する (どちらか片方だけの節を作らせない)", () => {
        expect(Object.keys(SECTION_CONTRACT_PHRASES).sort()).toEqual([...REQUIRED_SECTIONS].sort());
    });

    it.each(Object.entries(SECTION_CONTRACT_PHRASES))(
        "%s の本文が規範の最小断片を保っている",
        (heading, phrases) => {
            const body = extractSection(doc, heading).join("\n");
            expect(phrases.length, `${heading}: 最小断片が 0 件`).toBeGreaterThan(0);
            for (const phrase of phrases) {
                expect(body, `${heading}: 「${phrase}」が本文に無い`).toContain(phrase);
            }
        },
    );
});

describe("design-system-docs: Canonical source 表のパス", () => {
    it("表の 2 列目に並ぶリポジトリ相対パスがすべて実在する", () => {
        const section = extractSection(doc, "## Canonical source の宣言");
        // 同じセルに `@import "./tokens.css"` のようなコード片も入るため、
        // `/` 始まり (リポジトリ相対) のものだけをパスとして扱う。
        const paths = tableCellLiterals(section, 1)
            .filter((literal) => literal.startsWith("/"))
            .map((literal) => literal.slice(1));

        expect(paths.length, "表からパスが 1 件も取れない (抽出の空振り)").toBeGreaterThan(0);
        for (const relative of paths) {
            expect(
                fs.existsSync(path.join(REPO_ROOT, relative)),
                `Canonical source 表の ${relative} が実在しない`,
            ).toBe(true);
        }
    });
});

describe("design-system-docs: 検査目録の同期", () => {
    it("責務境界表の 1 列目と実在する検査ファイルが集合一致する (双方向)", () => {
        // 片側だけでは足りない —
        //   実体 → 文書 だけ: 検査を消したのに表の行が残るのを止められない
        //   文書 → 実体 だけ: 検査を足したのに書かないのを止められない
        const section = extractSection(doc, "## 検査の責務境界");
        const listed = tableCellLiterals(section, 0)
            .filter((literal) => literal.endsWith(".test.ts"))
            .sort();

        expect(listed.length, "責務境界表からパスが 1 件も取れない (抽出の空振り)").toBeGreaterThan(
            0,
        );
        expect(listed, "文書の責務境界表と実在する検査ファイルが食い違っている").toEqual([
            ...gateFiles(),
        ]);
    });
});
```

### tests/js/styles/tokens.test.ts

```ts
import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
// AtRule は値としても使う (親を遡る経路は判別 union にならないので instanceof で見る)。
import postcss, { AtRule, type Container, type Document, type Root, type Rule } from "postcss";
import tailwindcss from "@tailwindcss/postcss";
import { REPO_ROOT, designColors, designRamp, designRounded } from "./design-md";
import {
    COLOR_TOKEN_MAP,
    COMPILED_VALUE_EXEMPT_TOKENS,
    CSS_COLOR_SUFFIXES,
    RADIUS_TOKENS,
    ROUTE_LAYER_ANCHOR_TOKENS,
    TYPOGRAPHY_RAMPS,
} from "./inventory";

/*
 * tokens.test — tokens.css の宣言が Tailwind のコンパイルを通って生成 CSS に出ることを検査する。
 *
 * 【他の検査との境界】
 *   canonical-source-parity : DESIGN.md ⇔ tokens.css の**テキスト**一致 (正本 ⇔ 宣言)
 *   本ファイル              : tokens.css ⇒ **Tailwind 生成 CSS** (宣言 ⇒ 生成物)
 *   contrast-invariant      : DESIGN.md の色の可読性
 *   検査範囲は一部重なる。トークンの値を変えれば parity と本ファイルの双方が赤になり得るが、
 *   Tailwind の解釈が壊れる形 (`@theme` が効かなくなる等) は本ファイルだけが検出する。
 *
 * 【2 層に分ける理由】
 *   経路の層 (実 app.css) : アプリの入口から tokens.css へ実際に繋がっていることを見る。
 *                            **アンカー集合であって全件ではない**
 *   密閉の層 (組み立て入力): `source(none)` で自動走査を止め、`@source inline` で候補を明示供給する。
 *                            アプリの class 使用状況に依存せず全件を見る
 *
 * 【走査範囲を絞る理由 (重要)】
 *   テーマ変数は `@layer theme` 直下の `:root, :host` に出る。生成 CSS 全体を無差別に走査すると、
 *   `@theme` が壊れて素の `:root` 宣言になった場合でも同じ変数が拾えてしまい**緑で通る**
 *   (実測で確認済み)。よって themeVariables() は @layer theme の :root, :host の
 *   **直接の子宣言**だけを集める。
 *
 * 【保証しないもの】
 *   - Vite のビルド・アセット配信・ブラウザでの適用 (生成 CSS より先は見ていない)
 *   - `@import` の**順序**を入れ替えたときの破綻。実測では順序を入れ替えても生成物は壊れない。
 *     順序はリポジトリ規約であり、その固定は下の「取り込みの規約」が行う
 *   - font-family は**先頭 family だけ**を突き合わせる。フォールバック列は見ていない
 *   - 共有パーサ (design-md.ts) の**値の誤解析**。キーの取りこぼしは canonical-source-parity の
 *     集合一致が検出するが、値を誤って読む形は本ファイルも parity も同じ誤りを見るので検出できない
 */

/* ===== 検査対象の utility 候補 (注入と検査を同じ 1 つの値から作る) ===== */

const UTILITY_CANDIDATES = {
    color: CSS_COLOR_SUFFIXES.flatMap((s) => [`bg-${s}`, `text-${s}`, `border-${s}`]),
    radius: RADIUS_TOKENS.map((r) => `rounded-${r}`),
    ramp: TYPOGRAPHY_RAMPS.map((r) => `text-${r}`),
    hover: CSS_COLOR_SUFFIXES.filter((s) => s.endsWith("-hover")).map((s) => `hover:bg-${s}`),
} as const;

/**
 * 密閉入力を組み立てる。
 *
 * `source(none)` は Tailwind の自動ソース走査を止めるだけなので、
 * **候補を @source inline で与えないと utility は 1 つも生成されない**。
 * 注入する集合と検査する集合は UTILITY_CANDIDATES という 1 つの値から作る (2 か所に書かない)。
 */
function sealedInput(): string {
    const candidates = Object.values(UTILITY_CANDIDATES).flat().join(" ");
    return [
        '@import "tailwindcss" source(none);',
        '@import "../../../resources/css/tokens.css";',
        `@source inline("${candidates}");`,
    ].join("\n");
}

/*
 * @tailwindcss/postcss は `from` に渡したパスを鍵に結果をキャッシュする。
 * 1 つの `from` に 1 つの入力を対応させる (同じ from で別の入力を流さない)。
 * 密閉入力の from は実在しないパスでよい (相対 @import の解決にだけ使われる)。
 */
const SEALED_FROM = path.join(REPO_ROOT, "tests/js/styles/__sealed-tokens-input.css");
const APP_CSS_PATH = path.join(REPO_ROOT, "resources/css/app.css");

/** postcss + Tailwind でコンパイルする。失敗は握り潰さずそのまま伝播させる。 */
async function compile(css: string, from: string): Promise<Root> {
    const result = await postcss([tailwindcss()]).process(css, { from, to: undefined });
    return result.root;
}

interface CollectedDeclarations {
    readonly values: ReadonlyMap<string, string>;
    /** 同名で値の違う宣言が複数あったもの。空であることをテストが確かめる。 */
    readonly conflicts: readonly string[];
    /** 宣言に至るまでに通った条件つき at-rule の条件文 (`@media` の params 等)。 */
    readonly conditions: readonly string[];
}

/**
 * `@layer` は「どの層に置くか」を表すだけで**適用の条件ではない**。
 * それ以外の at-rule (`@media` / `@supports` / `@container` …) は条件つきである。
 */
function isConditionalAtRule(name: string): boolean {
    return name.toLowerCase() !== "layer";
}

/**
 * container の**直接の子宣言**と、その下の at-rule (`@media` 等) 配下の宣言を集める。
 *
 * **子孫の Rule には降りない** — `&:focus` のような別セレクタの宣言を混ぜないため。
 * 同名プロパティで値が違えば競合として記録する (後勝ちで黙らせない)。
 * 通った条件つき at-rule の条件文は `conditions` に残す — 呼び出し側が
 * 「どの条件の下でだけ成り立つ宣言か」を検査できるようにするため
 * (成立しない条件の中にしか無い宣言を「生成されている」と数えないため)。
 */
function collectDeclarations(container: Container): CollectedDeclarations {
    const values = new Map<string, string>();
    const conflicts = new Set<string>();
    const conditions = new Set<string>();

    const visit = (node: Container): void => {
        for (const child of node.nodes ?? []) {
            if (child.type === "decl") {
                const value = child.value.trim();
                const previous = values.get(child.prop);
                if (previous !== undefined && previous !== value) conflicts.add(child.prop);
                values.set(child.prop, value);
                continue;
            }
            if (child.type === "atrule") {
                if (isConditionalAtRule(child.name)) conditions.add(child.params.trim());
                visit(child);
            }
            // child.type === "rule" は辿らない
        }
    };
    visit(container);

    return { values, conflicts: [...conflicts].sort(), conditions: [...conditions].sort() };
}

/**
 * **ルート直下の** `@layer theme` の中の `:root, :host` 規則の**直接の子**である
 * CSS 変数だけを集める。
 *
 * 「Tailwind がテーマとして解釈し、条件なしで適用される結果」だけを見るための絞り込みであり、
 * ここを緩めると `@theme` の破損が検出できなくなる (ファイル冒頭の「走査範囲を絞る理由」)。
 * 条件つき at-rule (`@media` / `@supports` 等) の中に入った `@layer theme` は**採らない** —
 * 生成 CSS に文字列としては現れても、条件が成立しなければ画面には効かないためである。
 * `@media` 等で入れ子になった `:root` も同じ理由で採らない。
 */
function themeVariables(root: Root): CollectedDeclarations {
    const values = new Map<string, string>();
    const conflicts = new Set<string>();

    const themeLayers = (root.nodes ?? []).filter(
        (node): node is AtRule =>
            node.type === "atrule" &&
            node.name === "layer" &&
            node.params
                .split(",")
                .map((name) => name.trim())
                .includes("theme"),
    );

    for (const layer of themeLayers) {
        for (const node of layer.nodes ?? []) {
            // 直接の子の Rule だけを見る (@media 等で入れ子になった :root は採らない)
            if (node.type !== "rule") continue;
            const selectors = node.selector.split(",").map((sel) => sel.trim());
            if (!selectors.every((sel) => sel === ":root" || sel === ":host")) continue;

            for (const child of node.nodes ?? []) {
                if (child.type !== "decl" || !child.prop.startsWith("--")) continue;
                const value = child.value.trim().toLowerCase();
                const previous = values.get(child.prop);
                if (previous !== undefined && previous !== value) conflicts.add(child.prop);
                values.set(child.prop, value);
            }
        }
    }

    return { values, conflicts: [...conflicts].sort(), conditions: [] };
}

/** 祖先に条件つき at-rule (`@media` / `@supports` 等) があるか。 */
function hasConditionalAncestor(node: Rule): boolean {
    let current: Container | Document | undefined = node.parent;
    while (current !== undefined) {
        if (current instanceof AtRule && isConditionalAtRule(current.name)) return true;
        current = current.parent;
    }
    return false;
}

/**
 * selector が完全一致する規則を出現順に返す。
 *
 * **条件つき at-rule の中にある規則は数えない** — 成立しない `@supports` や
 * `@media` の中にしか無い規則を「生成されている」と数えると、
 * 画面に出ない utility で緑になってしまうためである
 * (`@layer` は層の指定であって条件ではないので通す)。
 */
function rulesWithSelector(root: Root, selector: string): readonly Rule[] {
    const found: Rule[] = [];
    root.walkRules((rule) => {
        if (rule.selector !== selector) return;
        if (hasConditionalAncestor(rule)) return;
        found.push(rule);
    });
    return found;
}

/**
 * 出現がちょうど 1 件であることを確かめて、その規則の**直接の**宣言を返す。
 * 0 件も重複もここで落ちる。子孫 (`&:hover` や `@media` の中) は含めない。
 */
function soleRule(root: Root, selector: string): ReadonlyMap<string, string> {
    const rules = rulesWithSelector(root, selector);
    expect(rules.length, `${selector} の規則が 1 件でない (実際 ${rules.length} 件)`).toBe(1);

    const decls = new Map<string, string>();
    for (const node of rules[0].nodes ?? []) {
        if (node.type !== "decl") continue;
        decls.set(node.prop, node.value.trim());
    }
    return decls;
}

/**
 * `.hover\:…` 規則の中の **`&:hover` 入れ子配下**の宣言を返す。
 *
 * 外側の規則も `&:hover` も**ちょうど 1 件**であることを確かめてから中を見る
 * (複数出現を後勝ちで黙らせない)。`&:focus` のような別セレクタの入れ子には降りない。
 * `&:hover` の下でさらに `@media (hover: hover)` に包まれる形は Tailwind の実装詳細だが、
 * **どんな条件でも通す**と `@media (hover: none)` のように**成立時に打ち消す条件**へ
 * 移されても緑になる。そこで宣言は拾いつつ、通った条件は `conditions` として返し、
 * 呼び出し側 (D) が許容する条件の一覧と突き合わせる。
 * 条件の綴りが Tailwind の版で変わったら赤になるが、それは
 * 「新しい出力形に合わせて読み方を直す」契機であって緩める理由にはしない
 * (ファイル冒頭のリスク欄と同じ方針)。
 */
function hoverDeclarations(root: Root, selector: string): CollectedDeclarations {
    const outer = rulesWithSelector(root, selector);
    expect(outer.length, `${selector} の規則が 1 件でない (実際 ${outer.length} 件)`).toBe(1);

    const hovers = (outer[0].nodes ?? []).filter(
        (node): node is Rule => node.type === "rule" && node.selector === "&:hover",
    );
    expect(hovers.length, `${selector} の &:hover が 1 件でない (実際 ${hovers.length} 件)`).toBe(1);

    return collectDeclarations(hovers[0]);
}

/** font-family 宣言の先頭 family を引用符抜き・小文字で取り出す。 */
function firstFamily(value: string): string {
    const head = value.split(",")[0].trim();
    return head.replace(/^['"]|['"]$/g, "").toLowerCase();
}

let sealed: Root;
let routed: Root;

beforeAll(async () => {
    sealed = await compile(sealedInput(), SEALED_FROM);
    routed = await compile(fs.readFileSync(APP_CSS_PATH, "utf-8"), APP_CSS_PATH);
}, 60_000);

/* ===== 空振り防止 ===== */

describe("tokens: 空振り防止", () => {
    it.each(Object.entries(UTILITY_CANDIDATES))(
        "utility 候補の区分 %s が 0 件でない",
        (_kind, list) => {
            // 注入と検査を 1 つの値から作るので、組み立てが壊れると両方が同時に空になり
            // 緑のまま通る。区分ごとに非空を確かめてそれを防ぐ。
            expect(list.length).toBeGreaterThan(0);
        },
    );

    it("密閉入力の生成 CSS がテーマ変数を持つ", () => {
        expect(themeVariables(sealed).values.size).toBeGreaterThan(0);
    });

    it("負のコントロール: 実在しない utility の規則は 0 件になる", () => {
        // rulesWithSelector が「何にでも一致して緑になる」実装でないことを確かめる
        expect(rulesWithSelector(sealed, ".bg-does-not-exist-token").length).toBe(0);
    });
});

/* ===== ヘルパの仕様固定 (fixture) =====
 *
 * 走査の絞り込みは本ファイルの検出力そのものである (絞りを外すと @theme の破損が
 * 検出できなくなる)。生成 CSS を相手にした検査だけだと「絞りが効いているから緑」なのか
 * 「絞りが無くても緑」なのか区別できないので、**壊れた形を含む小さな CSS** を
 * postcss で読ませて、ヘルパの仕様を恒久的に固定する。
 */

const HELPER_FIXTURE = `
@layer theme {
    :root, :host {
        --fixture-token: ok;
        --fixture-conflict: a;
        --fixture-conflict: b;
    }
    @media (min-width: 1px) {
        :root { --fixture-media: ng; }
    }
}
@supports (color: oklch(0 0 0)) {
    @layer theme {
        :root, :host { --fixture-conditional-layer: ng; }
    }
}
:root { --fixture-outside: ng; --fixture-token: ng; }
@layer utilities {
    .fixture-util {
        color: ok;
        .fixture-child { color: ng; }
    }
    @supports (color: oklch(0 0 0)) {
        .fixture-conditional-util { color: ng; }
    }
    .hover\\:fixture {
        &:hover { @media (hover: hover) { background-color: ok; } }
        &:focus { background-color: ng; }
    }
}
`;

describe("tokens: ヘルパの仕様固定 (fixture)", () => {
    const fixture = postcss.parse(HELPER_FIXTURE, { from: undefined });

    it("themeVariables はルート直下の @layer theme の :root/:host だけを見る", () => {
        const { values, conflicts } = themeVariables(fixture);

        expect(values.get("--fixture-token"), "テーマ層の値を採る").toBe("ok");
        expect(values.has("--fixture-outside"), "テーマ層の外は採らない").toBe(false);
        expect(values.has("--fixture-media"), "@media の入れ子は採らない").toBe(false);
        expect(
            values.has("--fixture-conditional-layer"),
            "条件つき at-rule の中のテーマ層は採らない",
        ).toBe(false);
        expect(conflicts, "同名で値の違う宣言は競合として出す").toEqual(["--fixture-conflict"]);
    });

    it("soleRule は規則の直接の宣言だけを返す", () => {
        expect(Object.fromEntries(soleRule(fixture, ".fixture-util"))).toEqual({ color: "ok" });
    });

    it("負のコントロール: 条件つき at-rule の中にしかない規則は数えない", () => {
        // 成立しない @supports / @media の中にしか無い utility を「生成されている」と
        // 数えると、画面に出ないものを緑で通してしまう。
        expect(rulesWithSelector(fixture, ".fixture-conditional-util").length).toBe(0);
    });

    it("hoverDeclarations は &:hover 配下だけを返し、&:focus を混ぜない", () => {
        const { values, conflicts, conditions } = hoverDeclarations(fixture, ".hover\\:fixture");
        expect(Object.fromEntries(values)).toEqual({ "background-color": "ok" });
        expect(conflicts).toEqual([]);
        expect(conditions, "通った条件つき at-rule を呼び出し側へ返す").toEqual(["(hover: hover)"]);
    });

    it("負のコントロール: 打ち消す条件の中の hover 宣言は条件として現れる", () => {
        // 条件を一切見ない実装だと「hover 時の背景色になる」が (hover: none) でも緑になる。
        const negated = postcss.parse(
            `.hover\\:negated { &:hover { @media (hover: none) { background-color: ng; } } }`,
            { from: undefined },
        );
        const { conditions } = hoverDeclarations(negated, ".hover\\:negated");
        expect(conditions).toEqual(["(hover: none)"]);
        expect(ALLOWED_HOVER_CONDITIONS).not.toContain("(hover: none)");
    });
});

/* ===== A. テーマ変数 (密閉の層) ===== */

describe("tokens/A: @theme 由来の CSS 変数が生成 CSS に期待値で現れる", () => {
    it("同名変数の値が競合していない", () => {
        expect(themeVariables(sealed).conflicts).toEqual([]);
    });

    it.each(Object.entries(COLOR_TOKEN_MAP))(
        "DESIGN.md colors.%s の値が --color-%s に届く",
        (designKey, cssSuffix) => {
            const expected = designColors().get(designKey);
            expect(expected, `DESIGN.md colors に ${designKey} が無い`).toBeDefined();
            expect(themeVariables(sealed).values.get(`--color-${cssSuffix}`)).toBe(expected);
        },
    );

    it.each(Object.keys(COMPILED_VALUE_EXEMPT_TOKENS))(
        "派生トークン --color-%s は出現までを検査する (値は免除)",
        (suffix) => {
            // 値の突き合わせを免除する理由は inventory.ts の COMPILED_VALUE_EXEMPT_TOKENS にある。
            // 「見ていない」のは値だけで、出現そのものは見る。
            expect(themeVariables(sealed).values.has(`--color-${suffix}`)).toBe(true);
        },
    );

    it.each([...RADIUS_TOKENS])("DESIGN.md rounded.%s の値が対応する --radius-* に届く", (key) => {
        // ⚠ Tailwind 既定テーマにも --radius-sm/md/lg がある (0.25rem / 0.375rem / 0.5rem)。
        //    「存在するか」だけでは空振りするので、必ず値を突き合わせる。
        expect(themeVariables(sealed).values.get(`--radius-${key}`)).toBe(designRounded().get(key));
    });

    it("ramp の font-family が 1 つに揃っており、--font-sans の**先頭 family**と一致する", () => {
        // ⚠ --font-sans も Tailwind 既定テーマに存在する。ここも値で見る。
        //    フォールバック列は DESIGN.md 側が持っていないので突き合わせない (先頭 family だけ)。
        const families = new Set(TYPOGRAPHY_RAMPS.map((r) => designRamp(r)["fontFamily"]));
        expect(families.size, "DESIGN.md の ramp が複数の fontFamily を宣言している").toBe(1);

        const declared = [...families][0];
        const fontSans = themeVariables(sealed).values.get("--font-sans");
        expect(fontSans, "--font-sans が @layer theme に無い").toBeDefined();
        expect(firstFamily(fontSans ?? "")).toBe(firstFamily(declared));
    });
});

/* ===== B. typography ramp utility (密閉の層) ===== */

describe("tokens/B: ramp utility が DESIGN.md の値で生成される", () => {
    it.each([...TYPOGRAPHY_RAMPS])("text-%s の宣言が DESIGN.md と過不足なく一致する", (name) => {
        const design = designRamp(name);
        const decls = soleRule(sealed, `.text-${name}`);

        const expected = new Map<string, string>([
            ["font-family", "var(--font-sans)"],
            ["font-size", design["fontSize"]],
            ["font-weight", design["fontWeight"]],
            ["line-height", design["lineHeight"]],
        ]);
        // letterSpacing が DESIGN.md に**無い** ramp に letter-spacing を勝手に足すことも防ぐ
        // (キー集合の一致で見る)。
        if (design["letterSpacing"]) expected.set("letter-spacing", design["letterSpacing"]);

        expect(Object.fromEntries([...decls].sort())).toEqual(
            Object.fromEntries([...expected].sort()),
        );
    });
});

/* ===== C. 色 utility (密閉の層) ===== */

describe("tokens/C: 色 utility が var(--color-*) を参照して生成される", () => {
    it.each([...CSS_COLOR_SUFFIXES])("%s の bg / text / border が解決する", (suffix) => {
        const token = `var(--color-${suffix})`;
        expect(Object.fromEntries(soleRule(sealed, `.bg-${suffix}`))).toEqual({
            "background-color": token,
        });
        expect(Object.fromEntries(soleRule(sealed, `.text-${suffix}`))).toEqual({ color: token });
        expect(Object.fromEntries(soleRule(sealed, `.border-${suffix}`))).toEqual({
            "border-color": token,
        });
    });
});

/* ===== D. hover variant (密閉の層) ===== */

/**
 * `&:hover` の中で宣言を包んでよい条件つき at-rule の一覧。
 *
 * Tailwind v4 は hover 可能な入力機器に限る `@media (hover: hover)` で包む (実測)。
 * ここに無い条件が現れたら赤になる — 打ち消す条件 (`(hover: none)` 等) へ
 * 移された場合に緑で通さないための allowlist である。
 */
const ALLOWED_HOVER_CONDITIONS: readonly string[] = ["(hover: hover)"];

describe("tokens/D: hover variant が &:hover の中で解決する", () => {
    it.each([...UTILITY_CANDIDATES.hover])("%s が hover 時の背景色になる", (utility) => {
        const suffix = utility.replace("hover:bg-", "");
        const { values, conflicts, conditions } = hoverDeclarations(sealed, `.hover\\:bg-${suffix}`);
        expect(conflicts).toEqual([]);
        expect(values.get("background-color")).toBe(`var(--color-${suffix})`);
        for (const condition of conditions) {
            expect(ALLOWED_HOVER_CONDITIONS, `想定外の条件 ${condition}`).toContain(condition);
        }
    });
});

/* ===== E. radius utility (密閉の層) ===== */

describe("tokens/E: radius utility が var(--radius-*) を参照する", () => {
    it.each([...RADIUS_TOKENS])("rounded-%s が解決する", (key) => {
        // ⚠ この参照自体は Tailwind 既定テーマでも成立する (実測)。
        //    「aicue の値であること」を保証するのは A の値検査であって本テストではない。
        expect(Object.fromEntries(soleRule(sealed, `.rounded-${key}`))).toEqual({
            "border-radius": `var(--radius-${key})`,
        });
    });
});

/* ===== F. 経路の層 (実 app.css) ===== */

describe("tokens/F: 実 app.css のコンパイルで tokens.css が実際に効いている", () => {
    it("同名変数の値が競合していない", () => {
        // 誤った値のあとに正しい値が再宣言されると、後勝ちで正しい値だけが見えてしまう。
        // 密閉の層と同じく経路の層でも競合そのものを落とす。
        expect(themeVariables(routed).conflicts).toEqual([]);
    });

    it.each([...ROUTE_LAYER_ANCHOR_TOKENS])(
        "アンカー --color-%s が DESIGN.md の値で現れる",
        (suffix) => {
            // アンカー集合であって全件ではない (全件は密閉の層が見る)。
            // アンカーが使われなくなったら、テストを緩めず土台の別トークンへ差し替える。
            const designKey = Object.entries(COLOR_TOKEN_MAP).find(([, v]) => v === suffix)?.[0];
            expect(designKey, `COLOR_TOKEN_MAP に --color-${suffix} の対応が無い`).toBeDefined();
            expect(themeVariables(routed).values.get(`--color-${suffix}`)).toBe(
                designColors().get(designKey ?? ""),
            );
        },
    );

    it("主 CTA の塗り (.bg-primary) が生成される", () => {
        expect(Object.fromEntries(soleRule(routed, ".bg-primary"))).toEqual({
            "background-color": "var(--color-primary)",
        });
    });

    it("生成された自前トークンの値はすべて DESIGN.md と一致する", () => {
        // アンカー以外にも出ているトークンがあれば、ついでに値を確かめる (母集団は要求しない)。
        const vars = themeVariables(routed).values;
        for (const [designKey, cssSuffix] of Object.entries(COLOR_TOKEN_MAP)) {
            const actual = vars.get(`--color-${cssSuffix}`);
            if (actual === undefined) continue;
            expect(actual, `--color-${cssSuffix}`).toBe(designColors().get(designKey));
        }
    });
});

/* ===== G. 取り込みの規約 (AST でのテキスト検査) ===== */

describe("tokens/G: app.css の入口 2 行の規約", () => {
    it("最初の 2 つの at-rule が tailwindcss → ./tokens.css の @import である", () => {
        // これは**規約**の固定であって動作の不変条件ではない。
        // 実測では @import の順序を入れ替えても Tailwind v4 の生成物は壊れなかった。
        // 取り込みが失われる形の破綻は F (経路の層) が検出する。
        //
        // 行ベースでコメントを除くと複数行コメントを誤って解釈するので、
        // postcss で parse した AST の先頭ノードを見る。
        const appRoot = postcss.parse(fs.readFileSync(APP_CSS_PATH, "utf-8"), {
            from: APP_CSS_PATH,
        });
        const significant = appRoot.nodes.filter((node) => node.type !== "comment");

        expect(significant.length).toBeGreaterThanOrEqual(2);
        const [first, second] = significant;
        expect(first.type).toBe("atrule");
        expect(second.type).toBe("atrule");
        if (first.type !== "atrule" || second.type !== "atrule") return;

        expect(first.name).toBe("import");
        expect(first.params).toMatch(/^["']tailwindcss["']$/);
        expect(second.name).toBe("import");
        expect(second.params).toMatch(/^["']\.\/tokens\.css["']$/);
    });
});
```

### docs/design-system.md の該当節 (差分後)

```markdown
## 検査の責務境界

本節で責務境界を管理するデザイントークン検査は 4 本ある
(DS purity 系など、トークンの値以外を見る検査は本節の管理対象ではない)。
**どれが何を見ているか**を混同しないこと — 見ている写像の段が違うので、
片方を消すと別の壊れ方が見えなくなる。

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/canonical-source-parity.test.ts` | DESIGN.md (正本) ⇔ tokens.css (宣言) のテキスト | 片方だけ更新した PR / トークンの増減 / 検査の母集団の取りこぼし |
| `tests/js/styles/tokens.test.ts` | tokens.css (宣言) ⇒ Tailwind 生成 CSS | `@theme` が解釈されない / utility 名が解決しない / app.css が tokens.css を取り込んでいない |
| `tests/js/styles/design-system-docs.test.ts` | 本書の構造 ⇔ 検査ファイルの実体 | 運用契約の節の消失 / 表と実体の食い違い |
| `tests/js/architecture/contrast-invariant.test.ts` | DESIGN.md の色値 ⇒ コントラスト比 | 読めない色の組合せ |

**この表は機械で実体と突き合わせている**。`tests/js/styles/` に検査を足したら本表にも行を足す
(足さないと `design-system-docs.test.ts` が落ちる)。逆に検査を消したら行も消す。
別の場所へ足す検査は `design-system-docs.test.ts` の `EXTERNAL_GATE_FILES` へ明示登録する。

本書の検査は、読者に描画されない領域 (HTML コメント / fenced code) を落としてから節と表を見る。
落とす判定は Markdown の規則に合わせてあり、字下げした偽の終端では区間が閉じない。
そのうえで節ごとに**規範の最小断片** (`design-system-docs.test.ts` の
`SECTION_CONTRACT_PHRASES`) が本文に在ることを求めるので、契約の一文を消したり
描画されない領域へ移したりすると赤になる。**文言を直すときは同じ PR で最小断片も直す**
(それが「契約を変えた」ことの可視化になる)。

保証しないもの: Vite のビルド・アセット配信・ブラウザでの適用は 4 本のどれも見ていない。
文書側で見ているのは節の構造・表の実体・最小断片までで、**周りの説明が骨抜きになったことは
検出できない**。
DESIGN.md frontmatter の `spacing:` は**値も tokens.css への実装写像の有無も検査していない**
(未検査であることは `tests/js/styles/inventory.ts` の `FRONTMATTER_SECTION_OWNERS` に
理由・解消条件・追跡先つきで宣言してある)。

## トークン変更時の運用契約
```

### docs/template-divergence.md D27 の直した 2 箇所

1531:| 文書の検査 | 散文の完全一致フレーズ | 節・表のセル・パス・検査目録の構造検査 + 節ごとの規範の最小断片 (描画されない領域を先に除く) |
1565:- 文書側は構造と節ごとの規範の最小断片までを見る。最小断片が在っても

## 検証コマンドの結果 (全レーン完了)

| コマンド | 結果 |
|---|---|
| `composer test` | pest passed / tests 5770 (passed 5768 / skipped 2 / risky 5) / assertions 25293 |
| `pnpm test` | Test Files 162 passed / Tests 2098 passed |
| `pnpm test:packages` | Test Files 10 passed / Tests 106 passed |
| `composer phpstan` | No errors |
| `vendor/bin/pint --test` | passed |
| `pnpm typecheck` / `pnpm lint` / `pnpm build` | passed |
| `pnpm typecheck:packages` / `pnpm build:packages` | passed |
| `pnpm exec eslint tests/js/styles/*.ts` | passed |

## 依頼

Round 1 の [Critical] 3 件と [Warning] 5 件について、対応内容が指摘を実際に解消しているかを判定してほしい。
見送った 2 件 (`:root`/`:host` 単独の受理 / 行内コード文脈) の判断が妥当かどうかも述べること。
最後に全体判定を APPROVED か CHANGES_REQUESTED で 1 行書くこと。
