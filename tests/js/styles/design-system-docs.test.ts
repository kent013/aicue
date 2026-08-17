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
 * HTML コメントを取り除いた跡には**目印 (HIDDEN_MARK) を 1 つ残す**。詰めて繋ぐと、
 * 読者には離れて見える 2 つの断片が検査の上でだけ 1 つの文字列になり、
 * 規範の最小断片と一致してしまう (行内コードの中にコメントを置く形で作れる)。
 * 目印を**空白にしてはいけない** — 最小断片が元々空白を含む位置
 * (`同一 PR 内で` の空白等) にコメントを置かれると、空白では一致を防げないためである。
 *
 * 閉じないまま EOF に達したら、そこまでを潰す。
 */
const FENCE_OPEN = /^ {0,3}(`{3,}|~{3,})/;
const FENCE_CLOSE = /^ {0,3}(`{3,}|~{3,})[ \t]*$/;

/**
 * コメントを取り除いた跡に残す目印。垂直タブ (U+000B) を使う。
 *
 * 要件は 2 つある。
 *   1. **規範の最小断片には使わない文字**であること。半角空白のように断片へ現れる文字だと、
 *      最小断片が元々空白を含む位置 (`同一 PR 内で` の空白等) を狙って断片を合成できてしまう
 *   2. **`trim()` が空白として落とす文字**であること。落とさない文字 (U+0000 等) だと、
 *      コメントだけの行が「本文のある行」に見えて節の非空検査をすり抜ける
 * 垂直タブはこの 2 つを同時に満たす (最小断片には使わない / `trim()` の対象)。
 * ファイルに格納できないという意味ではない — 使わないと決めているだけである。
 */
const HIDDEN_MARK = "\u000B";

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
            // コメントの終端より後ろだけを描画される本文として残す (跡に目印を置く)
            line = HIDDEN_MARK + line.slice(end + 3);
            inComment = false;
        }

        // 同一行に閉じる HTML コメントは繰り返し取り除く (跡には目印を 1 つ残す)
        for (;;) {
            const start = line.indexOf("<!--");
            if (start < 0) break;
            const end = line.indexOf("-->", start + 4);
            if (end < 0) {
                line = line.slice(0, start) + HIDDEN_MARK;
                inComment = true;
                break;
            }
            line = line.slice(0, start) + HIDDEN_MARK + line.slice(end + 3);
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
