# impl-review Round 3

Round 2 の指摘への対応が済んだので再レビューを依頼する。

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

Codex の全体判定は **CHANGES_REQUESTED**。Round 1 の Critical 3 件のうち 1 件 (テーマ層の走査範囲) と
Warning 2 件は解消と判定され、残りに 3 つの穴が指摘された。

## [Critical] バッククォート fence の情報文字列にバッククォートを含む行を開始 fence と誤認する

- 判断: **対応する**
- 根拠: CommonMark ではバッククォートで開く fence の情報文字列にバッククォートを置けない。
  そういう行を開始 fence として扱うと、**次に来る本物の開始 fence を終端と誤認して区間が 1 つずれ**、
  隠したい本文が「描画される本文」として通る。Round 1 の 4 空白の偽終端と同じ種類の fail-open。
- 対応内容: 開始 fence の判定で、記号がバッククォートのときだけ後続にバッククォートが無いことを
  要求した。fixture に「無効な開始 fence の行は本文として残り、その次の本物の fence の中は隠れる」
  という負のコントロールを足した。

## [Critical] 行内コードの中の HTML コメントを取り除くと前後が繋がり、最小断片と一致する

- 判断: **対応する** (Round 1 の「見送る」を撤回する)
- 根拠: 指摘のとおり `` `DESIGN.md が唯一<!-- 印 -->の真実` `` は読者には最小断片と違う文に
  見えるのに、詰めて繋ぐと検査の上でだけ一致する。最小断片の検査を足した以上、
  これは誤って潰す方向の誤りではなく fail-open である。
- 対応内容: コメントを取り除いた跡に**空白を 1 つ残す**ようにした
  (Markdown パーサを持ち込まずに、離れて見える 2 つの断片が繋がらないことを保証できる)。
  負の fixture でこの並びが最小断片と一致しないことを固定した。
  完全な Markdown 解析ではないことは docblock と `docs/design-system.md` に明記した。

## [Warning] hover の条件が at-rule 名を捨てるため `@supports (hover: hover)` を許す

- 判断: **対応する**
- 根拠: 条件文だけで照合すると別種の at-rule を同じ条件として許してしまう。
- 対応内容: `conditions` を `<名前> <条件文>` の形にし、許容一覧を
  `["media (hover: hover)"]` にした。負の fixture に `@supports (hover: hover)` を足した。

## [Warning] `:root` / `:host` 単独の受理は妥当だが、文書側と同期すること

- 判断: **対応する (文書の同期のみ)**
- 根拠: 見送りの判断自体は妥当と評価された。残る作業は「完全一致は要求しない」と書くことだけ。
- 対応内容: `themeVariables()` の docblock に「`:root, :host` の完全一致は要求しない。
  守りたいのは条件なしでテーマ変数が到達すること」と明記した。

## [要修正] 文書の保証が過大 (`docs/design-system.md` / D27)

- 判断: **対応する**
- 根拠: 上の 2 つの解析穴を塞いだので保証は成立するが、
  「完全な Markdown 解析ではない」ことは書いておくべきである。
- 対応内容: `docs/design-system.md` に fence 規則の具体と、
  4 空白字下げのコードブロック・HTML 要素による非表示を見ていないことを明記した。
  D27 の「保証しないもの」は Round 1 の対応で既に同じ範囲を書いてある。

## 感度確認のやり直し

Round 1・2 の修正はすべて走査の絞りを**強める**方向だが、記録を最終コードで取り直すため
`run-red-verification.sh` を再実行した。基準は 117 件から **130 件**に増え (足した fixture の分)、
R1〜R6 の反応は前回と同じ (R5 だけは節の非空と最小断片の 2 本が赤になり 1 件増えた)。

## 修正後の全文

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
 *   - バッククォートで開く行の**情報文字列にバッククォートを含められない**。
 *     含む行は開始 fence ではないので通常の本文として扱う
 *     (fence 扱いにすると、その次の本物の開始 fence を終端と誤認して区間がずれる)
 *
 * HTML コメントを取り除いた跡には**空白を 1 つ残す**。詰めて繋ぐと、
 * 読者には離れて見える 2 つの断片が検査の上でだけ 1 つの文字列になり、
 * 規範の最小断片と一致してしまう (行内コードの中にコメントを置く形で作れる)。
 *
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

        // 同一行に閉じる HTML コメントは繰り返し取り除く (跡には空白を 1 つ残す)
        for (;;) {
            const start = line.indexOf("<!--");
            if (start < 0) break;
            const end = line.indexOf("-->", start + 4);
            if (end < 0) {
                line = line.slice(0, start);
                inComment = true;
                break;
            }
            line = line.slice(0, start) + " " + line.slice(end + 3);
        }

        const open = line.match(FENCE_OPEN);
        // バッククォート fence の情報文字列にバッククォートがある行は開始 fence ではない
        const infoString = open === null ? "" : line.slice(open[0].length);
        if (open !== null && !(open[1][0] === "`" && infoString.includes("`"))) {
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
    "```info`string",
    "無効な開始 fence の行は本文として残る",
    "```",
    "本物の fence の中の本文",
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

    it("負のコントロール: 情報文字列にバッククォートを含む行は開始 fence にならない", () => {
        // ここを fence 扱いにすると、次に来る本物の開始 fence を終端と誤認して
        // 区間が 1 つずれ、隠したい本文が描画される本文として通る。
        const body = rendered.join("\n");
        expect(body).toContain("無効な開始 fence の行は本文として残る");
        expect(body).not.toContain("本物の fence の中の本文");
    });

    it("負のコントロール: コメントを取り除いた跡で前後が繋がらない", () => {
        // 行内コードの中にコメントを置くと読者には離れて見えるのに、
        // 詰めて繋ぐと検査の上でだけ最小断片と一致してしまう。
        const spliced = renderedLines("`DESIGN.md が唯一<!-- 見える印 -->の真実`");
        expect(spliced.join("\n")).not.toContain("DESIGN.md が唯一の真実");
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

### tests/js/styles/tokens.test.ts の変更部分 (条件つき at-rule の扱いと themeVariables の docblock)

```ts
interface CollectedDeclarations {
    readonly values: ReadonlyMap<string, string>;
    /** 同名で値の違う宣言が複数あったもの。空であることをテストが確かめる。 */
    readonly conflicts: readonly string[];
    /**
     * 宣言に至るまでに通った条件つき at-rule を `<名前> <条件文>` の形で並べたもの。
     * **名前を捨てない** — `@media (hover: hover)` と `@supports (hover: hover)` は
     * 別物なので、条件文だけで照合すると後者を前者として許してしまう。
     */
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
                if (isConditionalAtRule(child.name)) {
                    conditions.add(`${child.name.toLowerCase()} ${child.params.trim()}`);
                }
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
 *
 * **selector は `:root` 単独・`:host` 単独も受理する** (`:root, :host` の完全一致は要求しない)。
 * 複合 selector の綴りは Tailwind v4 の出力形であって本アプリの不変条件ではないためで、
 * 守りたいのは「条件なしでテーマ変数が到達すること」の方である。
 */
function themeVariables(root: Root): CollectedDeclarations {
...
const ALLOWED_HOVER_CONDITIONS: readonly string[] = ["media (hover: hover)"];

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
...
    it("負のコントロール: 打ち消す条件の中の hover 宣言は条件として現れる", () => {
        // 条件を一切見ない実装だと「hover 時の背景色になる」が (hover: none) でも緑になる。
        const negated = postcss.parse(
            `.hover\\:negated { &:hover { @media (hover: none) { background-color: ng; } } }`,
            { from: undefined },
        );
        const { conditions } = hoverDeclarations(negated, ".hover\\:negated");
        expect(conditions).toEqual(["media (hover: none)"]);
        expect(ALLOWED_HOVER_CONDITIONS).not.toContain("media (hover: none)");
    });
    it("負のコントロール: 条件文が同じでも at-rule の種類が違えば別物として出る", () => {
        // 条件文だけで照合すると @supports (hover: hover) を @media (hover: hover) として
        // 許してしまう。名前を捨てないことをここで固定する。
        const supports = postcss.parse(
            `.hover\\:supports { &:hover { @supports (hover: hover) { background-color: ng; } } }`,
            { from: undefined },
        );
        const { conditions } = hoverDeclarations(supports, ".hover\\:supports");
        expect(conditions).toEqual(["supports (hover: hover)"]);
        expect(ALLOWED_HOVER_CONDITIONS).not.toContain("supports (hover: hover)");
    });
```

### docs/design-system.md の該当節

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
落とす判定は Markdown の fence 規則に寄せてあり (字下げした偽の終端や、
情報文字列にバッククォートを含む無効な開始行では区間が閉じない・開かない)、
コメントを取り除いた跡には空白を 1 つ残すので前後が繋がらない。
ただし**完全な Markdown 解析ではない** — 4 空白字下げのコードブロックと
HTML 要素による非表示は見ていない。
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

## 感度確認の再実測 (最終コード)

| # | 注入した故障 | 基準からの差分 |
|---|---|---|
| R0 | なし | 4 ファイル 130 件すべて緑 |
| R1 | app.css から tokens.css の取り込みを消す | 6 件赤 (経路の層のアンカー 4 + .bg-primary + 取り込みの規約) |
| R2 | @theme を :root にする | 39 件赤 (A の色 13 / 派生 1 / radius 3 / font 1 / C 14 / D 2 / F 5)。B・E・G は緑 |
| R3 | @utility text-body を消す | 4 件赤 (新設 2 + 既存 parity 2) |
| R4 | --color-danger の値を変える | 3 件赤 (新設 2 + 既存 parity 1) |
| R5 | 節を 1 つ改名する | 2 件赤 (節の非空 + その節の最小断片) |
| R6 | ダミーの .test.ts を置く | 1 件赤 (検査目録の集合一致) |

## 依頼

Round 2 の Critical 2 件と Warning 2 件について、対応が指摘を解消しているかを判定してほしい。
最後に全体判定を APPROVED か CHANGES_REQUESTED で 1 行書くこと。
