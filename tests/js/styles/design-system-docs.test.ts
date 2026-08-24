import { beforeAll, describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT } from "./design-md";
// 行の分類 (規範判定対象外領域の除去 / 字下げの禁止) は 1 実装へ集約する (正典 i21)。
import { scanMarkdownLines } from "./markdown-lines";

/*
 * design-system-docs — docs/design-system.md の**構造**が壊れていないことを検査する。
 *
 * 【見るもの】節の実在と本文の非空 / 節ごとの**規範の最小断片** / 表のセルに並ぶパスの実在 /
 *   検査目録の双方向の集合一致
 * 【見ないもの】散文そのもの。下の SECTION_CONTRACT_PHRASES に挙げた最小断片**以外**の
 *   言い回しは検査しない (文章を良くする PR を止めないため)
 *
 * 【規範判定対象外領域を先に落とす理由】
 *   Markdown の本文には「規範の本文として数えてはいけない」領域がある
 *   (HTML コメント = 読者に描画されない / 囲みコード = 描画されるが本文ではない)。
 *   契約の本文をそこへ移すだけで「節はあるし本文も空でない」状態を作れてしまうため、
 *   検査の前に該当行を空行へ潰す (fail-open を塞ぐ)。判定の正本は
 *   `tests/js/styles/markdown-lines.ts` (契約 A / 契約 B) で、本ファイルはその**消費先**である。
 *
 * 【保証しないもの】
 *   - **運用契約の意味が残っていること**。最小断片が本文に在ることまでしか見ておらず、
 *     周りの説明が骨抜きになっていることは検出できない
 *   - **規範判定対象外領域の全種類**。潰すのは HTML コメントと囲みコードの 2 つだけで、
 *     HTML 要素による非表示は見ていない。字下げによるコードは**潰さずに検査自体を失敗させる**
 *     (契約 B。近似で判定すると見出し直後や引用の中の形を取りこぼし、そこへ規範の断片を
 *     退避させられるため、書き方の側を禁じている)。
 *     また HTML コメントの除去は**行内コード (`` ` `` 囲み) の文脈を見ない**ので、
 *     行内コードとして書いた `<!-- … -->` は読者に見えていても潰される。
 *     ただし跡には目印 (HIDDEN_MARK) が残るため、**読者に見える文字を挟んだ断片が
 *     検査の上でだけ繋がって最小断片と一致する**ことは起きない
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
 * 規範判定対象外領域 (HTML コメント / 囲みコード) を空行へ潰した行配列を返す。
 *
 * 解析の正本は `markdown-lines.ts` の `scanMarkdownLines()` である
 * (本ファイルと `design-md.ts` の節抽出が同じ実装を使う = 正典 i21)。
 */
function renderedLines(doc: string): readonly string[] {
    return scanMarkdownLines(doc).renderedLines;
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

    it("負のコントロール: 最小断片が元々空白を含む位置にコメントを置いても繋がらない", () => {
        // 跡に残す目印を空白にすると、この形が最小断片と一致してしまう
        // (`同一 PR 内で` の空白の位置にコメントを置く形)。
        const spliced = renderedLines("`同一<!-- 見える印 -->PR 内で`");
        expect(spliced.join("\n")).not.toContain("同一 PR 内で");

        const head = renderedLines("`DESIGN.md<!-- 見える印 --> が唯一の真実`");
        expect(head.join("\n")).not.toContain("DESIGN.md が唯一の真実");
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

/* ===== 契約 A / 契約 B の仕様固定 (fixture) =====
 *
 * 「規範判定対象外領域の除去」と「字下げの禁止」は本ファイルの検出力そのものなので、
 * 実文書だけを相手にすると「効いているから緑」なのか「効かなくても緑」なのか区別できない。
 * 壊れた形・紛らわしい形を `scanMarkdownLines()` へ直接渡して両方向を固定する。
 */

const BACKTICK = "`";
const FENCE = BACKTICK.repeat(3);

const indentLines = (lines: readonly string[]): readonly number[] =>
    scanMarkdownLines(lines.join("\n")).forbiddenIndentLines;

const diagnosticReasons = (lines: readonly string[]): readonly string[] =>
    scanMarkdownLines(lines.join("\n")).diagnostics.map((d) => d.reason);

describe("design-system-docs: 契約 B — 字下げの禁止 (fixture)", () => {
    it.each([
        ["空行の後の 4 空白字下げ行", ["本文", "", "    退避させた規範"]],
        ["見出しの直後の 4 空白字下げ行", ["## 契約", "", "    退避させた規範"]],
        ["段落の継続行 (直前が空行でない 4 空白字下げ行)", ["本文", "    継続行"]],
        ["行頭タブ", ["本文", "\t退避させた規範"]],
        ["1〜3 空白 + タブ", ["本文", "  \t退避させた規範"]],
        ["引用の中の字下げ", ["> 本文", ">    退避させた規範"]],
        ["入れ子の引用の中の字下げ", ["> > 本文", "> >    退避させた規範"]],
        ["リストの中の字下げ", ["- 本文", "-      退避させた規範"]],
        ["番号つきリストの別記法", ["1) 本文", "1)     退避させた規範"]],
        ["行の途中の 4 連続空白", ["本文    退避させた規範"]],
        ["marker の padding 1", ["- 本文", "      退避させた規範"]],
        ["marker の padding 4", ["-    本文", "         退避させた規範"]],
        ["ordered marker 1 桁 + ピリオド", ["1. 本文", "1.     退避させた規範"]],
        ["ordered marker 9 桁 + 閉じ括弧", ["123456789) 本文", "123456789)     退避"]],
        ["リストの最初の block が字下げコード", ["-     退避させた規範"]],
        ["リストの後続 block が字下げコード", ["- 本文", "", "      退避させた規範"]],
        ["引用とリストの異種入れ子 (引用が外)", ["> - 本文", "> -     退避させた規範"]],
        ["引用とリストの異種入れ子 (リストが外)", ["- > 本文", "- >    退避させた規範"]],
    ])("%s を検出する", (_label, lines) => {
        expect(indentLines(lines).length).toBeGreaterThan(0);
    });

    it.each([
        ["lazy continuation は字下げコードではない", ["> 本文", "継続行"]],
        ["通常の引用本文", ["> 本文"]],
        ["通常のリスト本文と 2 空白の継続行", ["- 本文", "  継続行"]],
        ["1〜3 空白の字下げ行", ["本文", "   3 空白は字下げコードではない"]],
    ])("%s は検出しない (偽陽性を出さない)", (_label, lines) => {
        expect(indentLines(lines)).toEqual([]);
    });

    it("囲みコードの中の 4 空白字下げ行とタブは検出しない", () => {
        expect(indentLines([FENCE, "    字下げしたコード", "\tタブを含むコード", FENCE])).toEqual(
            [],
        );
    });
});

describe("design-system-docs: 契約 A — 規範判定対象外領域の除去 (fixture)", () => {
    it.each([
        ["引用の中の囲みコード記法", ["> " + FENCE, "> 退避させた規範", "> " + FENCE]],
        ["入れ子の引用の中の囲みコード記法", ["> > " + FENCE, "> > 退避", "> > " + FENCE]],
        ["リストの中の引用の中の囲みコード記法", ["- > " + FENCE, "- > 退避", "- > " + FENCE]],
        ["引用の中のリストの中の囲みコード記法", ["> - " + FENCE, "> - 退避", "> - " + FENCE]],
        ["2 空白 + 引用の囲みコード記法", ["  > " + FENCE, "  > 退避", "  > " + FENCE]],
        ["行の途中に現れる連続 marker", ["本文 " + FENCE + " 退避"]],
    ])("%s は container-fence の診断になる", (_label, lines) => {
        expect(diagnosticReasons(lines)).toContain("container-fence");
    });

    it("container を伴う fence 候補の中の見出しや規範は通常本文として数えられない", () => {
        const scan = scanMarkdownLines(["> " + FENCE, "> ### 部品名", "> " + FENCE].join("\n"));
        expect(scan.diagnostics.length).toBeGreaterThan(0);
    });

    it("3 個以上の delimiter の行内コード span も診断になる (1〜2 個は診断にならない)", () => {
        expect(diagnosticReasons(["本文 " + FENCE + "行内" + FENCE + " 本文"]).length).toBeGreaterThan(
            0,
        );
        expect(diagnosticReasons(["本文 " + BACKTICK + "行内" + BACKTICK + " 本文"])).toEqual([]);
        expect(
            diagnosticReasons([
                "本文 " + BACKTICK.repeat(2) + "行内" + BACKTICK.repeat(2) + " 本文",
            ]),
        ).toEqual([]);
    });

    it("正規の top-level fence は診断にならず、中身が落ちる", () => {
        const scan = scanMarkdownLines([FENCE, "囲みの中", FENCE, "本文"].join("\n"));
        expect(scan.diagnostics).toEqual([]);
        expect(scan.renderedLines.join("\n")).not.toContain("囲みの中");
        expect(scan.renderedLines.join("\n")).toContain("本文");
        expect(scan.renderedLines.length).toBe(4);
    });

    it("受理範囲外の fence 記法と未終端の fence が診断になる", () => {
        // 開始より短い終了 marker では閉じない → EOF まで開いたまま
        expect(diagnosticReasons([BACKTICK.repeat(4), "中身", FENCE])).toEqual([
            "unterminated-fence",
        ]);
        // 種類の違う終了 marker でも閉じない
        expect(diagnosticReasons([FENCE, "中身", "~~~"])).toEqual(["unterminated-fence"]);
        // backtick 型で情報文字列にバッククォートを含む行は開始 fence にならず診断になる
        expect(diagnosticReasons([FENCE + "info" + BACKTICK + "string", "本文"])).toEqual([
            "unsupported-fence",
        ]);
        // EOF まで閉じない fence
        expect(diagnosticReasons([FENCE, "中身"])).toEqual(["unterminated-fence"]);
    });

    it("未終端の HTML コメントが診断になる", () => {
        expect(diagnosticReasons(["<!-- 閉じないコメント", "ここも隠れる"])).toEqual([
            "unterminated-html-comment",
        ]);
    });
});

describe("design-system-docs: 実文書の行分類", () => {
    const source = fs.readFileSync(DOC_PATH, "utf-8");

    it("囲みコードの外にタブと 4 連続空白が無い", () => {
        const scan = scanMarkdownLines(source);
        expect(scan.renderedLines.length, "行が 1 行も取れない (走査の空振り)").toBeGreaterThan(0);
        expect(
            scan.forbiddenIndentLines,
            "囲みコードの外に字下げがある。字下げによるコードは書かず、囲みコード記法を使うこと",
        ).toEqual([]);
    });

    it("Markdown 走査の診断が 0 件である (本 gate が docs 側の診断の消費先である)", () => {
        expect(scanMarkdownLines(source).diagnostics).toEqual([]);
    });
});
