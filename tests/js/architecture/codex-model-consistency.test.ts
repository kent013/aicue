import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

/**
 * Codex に指定するモデルの正典を deny-by-default で固定する。
 *
 * 家系の機能台帳 lctl の機能 skill-codex-integration に対するオーナー裁定
 * AG-186 (2026-08-15) が「現行世代の 3 綴り」を正典と定めた。本テストは
 * 設計 devnotes/20260817-1309-todo-t209-codex-model-canon-update/ の 3 層構成で、
 * 綴りが正典どおりであること・用途と綴りの対応が入れ替わっていないこと・
 * 正本の表が痩せても増えてもいないことを固定する。
 *
 *   層 1: 走査根の全ファイルで、**正典 3 綴り以外の出現を 0 件で固定する**。
 *         ただし「モデル名ではないことが分かっている既知の文字列」だけは、
 *         パス・文字列・件数付きで目録 (`NON_MODEL_EXEMPTIONS`) へ登録すれば通る
 *         (件数は完全一致で pin され、候補が登録文字列に丸ごと収まるときだけ効く)。
 *         併せてファイルごとの出現回数も期待表と完全一致で突き合わせる。
 *   層 2: モデル宣言とラベル宣言の対から (用途 → 綴り) の写像を作り、
 *         期待写像と完全一致で突き合わせる (ファイル内の取り違えの検出)。
 *   層 3: 正本の「利用可能モデル」表の綴りが正典ちょうどであることを固定する。
 *
 * **走査根は `.claude` と `scripts` の git 追跡下の全ファイル**である。
 * `tests/` を含めないのは、本ファイルが負のコントロールの検体として旧世代の綴りや
 * 不正な綴りを必ず持つためである (自分が検出したい語を入力として持つファイルは
 * 走査から外す)。`devnotes/` と `docs/TODO-closed.md` は過去のレビュー実績の記録で
 * あり、書き換えは履歴の改竄にあたるため意図的に走査の外にある。
 *
 * **保証しないもの**: 検査はリポジトリ内の文字だけを見る (提供元へ疎通しないので
 * 「その綴りが今も使える」ことは保証しない)。検出は `gpt-` で始まる候補だけを見るので、
 * **`gpt-` で始まらない形のモデル名** (別の提供元・別の命名体系の名前) には沈黙する。
 * ラベルの語が用途を正しく表しているかも見ない (人のレビュー対象)。
 * 走査は字句であり、動的に組み立てた綴りやリポジトリの外にある手順は対象外である。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");

/** 正典の綴り。裁定 AG-186 (家系の機能台帳 skill-codex-integration) が正本。 */
const CANONICAL_MODELS = ["gpt-5.6-sol", "gpt-5.6-terra", "gpt-5.6-luna"] as const;
type CanonicalModel = (typeof CANONICAL_MODELS)[number];

/** 走査根 (repo root からの相対)。git 追跡下の全ファイルを見る。 */
const SCAN_ROOTS = [".claude", "scripts"] as const;

/** モデル指定の正本 (唯一の「利用可能モデル」表を持つファイル)。 */
const CANON_FILE = ".claude/skills/app-codex-vscode/SKILL.md";
/** 正本の表を載せる節の見出し (レベル 2 ちょうど)。 */
const CANON_HEADING = "利用可能モデル";

const REVIEW_PATH = ".claude/skills/app-codex-review/SKILL.md";
const DESIGN_PATH = ".claude/skills/app-design/SKILL.md";
const IMPL_PATH = ".claude/skills/app-implement/SKILL.md";

/**
 * 綴りが現れてよいファイルと、その出現回数の期待値。
 * ここに無いファイルは **0 件** が期待値である (既定拒否)。
 *
 * 正本のスキル文書と同じ割当がここにも書かれるのは意図した形である。
 * 意図 (この表) と実測 (ファイルの中身) を独立に突き合わせるので、片方だけ直すと
 * 赤になる。自動抽出で一本化すると、正本が壊れたときに検査も一緒に壊れて
 * 緑のまま通る側へ倒れる。**モデル世代を上げるときは正本と本表を同じ変更で直す。**
 */
const EXPECTED_OCCURRENCES: Readonly<
    Record<string, Readonly<Partial<Record<CanonicalModel, number>>>>
> = {
    [CANON_FILE]: {
        "gpt-5.6-sol": 1,
        "gpt-5.6-terra": 1,
        "gpt-5.6-luna": 1,
    },
    [REVIEW_PATH]: { "gpt-5.6-sol": 1 },
    [DESIGN_PATH]: { "gpt-5.6-terra": 2, "gpt-5.6-sol": 2 },
    [IMPL_PATH]: { "gpt-5.6-sol": 1 },
};

/**
 * 用途 (label) → 綴り の期待写像。キーは「相対パス#label」。
 * 概念設計に議論向け・詳細設計と実装レビューにコード向けを充てる、が意図。
 */
const EXPECTED_ASSIGNMENTS: Readonly<Record<string, CanonicalModel>> = {
    [`${DESIGN_PATH}#conceptual-review`]: "gpt-5.6-terra",
    [`${DESIGN_PATH}#design-review`]: "gpt-5.6-sol",
    [`${IMPL_PATH}#impl-review`]: "gpt-5.6-sol",
};

/**
 * 候補の抽出は **貪欲** に行う。接尾辞・下線・記号の続きまで 1 つの候補に巻き込み、
 * 「正典の一部だけを取り出して合格にする」経路を作らない
 * (境界を持たない書き方だと `gpt-5.6-sol_preview` から正典部分だけを抜き出して
 *  数えてしまう)。`gpt-` に続く形は問わない = `gpt-beta` のような数字を持たない綴りも
 * 候補になる (受理できなかった候補はすべて違反である)。
 */
const MODEL_CANDIDATE_PATTERN = /gpt-[A-Za-z0-9._-]*/gi;

/**
 * **モデル名ではない**ことが分かっている既知の文字列の目録 (deny-by-default の例外)。
 *
 * 候補の抽出は貪欲なので、`openai.chatgpt-…` のような別語の中の `gpt-` も候補になる。
 * 候補を狭めて黙らせる (例えば数字始まりに限る) と、`gpt-beta` のような綴りまで
 * 無言で通す fail-open になるため、**候補は広いまま**にして、
 * ここへパス・文字列・**件数**付きで登録する。件数は完全一致で、増えても減っても赤になる。
 *
 * **例外が効くのは候補が登録文字列の中に丸ごと収まるときだけ**である
 * (開始位置だけを見ると `openai.chatgpt-gpt-beta` のように登録文字列の直後へ
 *  綴りを継ぎ足して握り潰させる経路ができる)。
 * 登録文字列は実際の識別子まで含めて書き、`gpt-` を含むだけの短い断片にしない。
 */
const NON_MODEL_EXEMPTIONS: readonly {
    readonly path: string;
    readonly literal: string;
    readonly count: number;
    readonly rationale: string;
}[] = [
    {
        path: "scripts/codex",
        literal: "openai.chatgpt-",
        count: 3,
        rationale:
            "VSCode 拡張 openai.chatgpt の識別子であり、モデル名ではない "
            + "(ラッパが拡張の実体を動的検出するために持つ)",
    },
    {
        path: "scripts/codex",
        literal: "openai\\.chatgpt-",
        count: 1,
        rationale:
            "同じ識別子を sed の正規表現へ埋めた形 (ドットを退避してある) であり、"
            + "モデル名ではない",
    },
];

/** モデル名に使える文字。候補の直前がこれなら別語の一部である。 */
const IDENTIFIER_CHAR = /[A-Za-z0-9_.-]/;

/**
 * 本検査が採用する **行頭限定** の見出し規則。`#` の並びの後ろに空白か行末が要る。
 * (CommonMark は先行する空白を 3 文字まで許すが、本検査は行頭固定に限る =
 *  保証範囲はこの字句規則までである。`#not-a-heading` は見出しではない)
 */
const HEADING_PATTERN = /^(#{1,6})(?:[ \t]+|$)/;

const MODEL_DECL_HEAD = /^[ \t]*\*\*model\*\*[ \t]*:/;
const LABEL_DECL_HEAD = /^[ \t]*\*\*label\*\*[ \t]*:/;
const MODEL_DECL_STRICT = /^[ \t]*\*\*model\*\*[ \t]*:[ \t]*`([^`]+)`[ \t]*$/;
const LABEL_DECL_STRICT = /^[ \t]*\*\*label\*\*[ \t]*:[ \t]*`([^`]+)`[ \t]*$/;

/**
 * GFM 相当のフェンス開始行。インデントは半角スペース **0〜3 個まで**に限る
 * (CommonMark はタブ始まり・4 スペース以上のインデントを indented code block として扱い、
 *  フェンス開始と認めない。ここを緩めると、4 スペース分だけ字下げした偽フェンスで
 *  正本の見出し・表を隠して検査を誤誘導できる)。
 */
const FENCE_OPEN = /^ {0,3}(`{3,}|~{3,})(.*)$/;
const FENCE_CLOSE = /^ {0,3}(`{3,}|~{3,})[ \t]*$/;

/**
 * フェンス開始行を解釈する。バッククォート系フェンスは info string に
 * バッククォートを含められない (CommonMark の規則)。含んでいたら
 * フェンス開始として認めない (info string の中の `` ` `` は文字どおりの文字であり、
 * 別のフェンスの開始ではない)。
 */
const parseFenceOpen = (line: string): { readonly marker: string; readonly length: number } | null => {
    const matched = FENCE_OPEN.exec(line);
    if (matched === null) return null;
    const run = matched[1] ?? "";
    const infoString = matched[2] ?? "";
    if (run.charAt(0) === "`" && infoString.includes("`")) return null;
    return { marker: run.charAt(0), length: run.length };
};

/** GFM の区切りセル 1 つの規則。ハイフンは 3 文字以上を要求する (1〜2 文字は緩すぎる)。 */
const TABLE_DELIMITER_CELL = /^:?-{3,}:?$/;
const FIRST_CELL_MODEL = /^[ \t]*`([^`]+)`[ \t]*$/;

/**
 * 正本の表のセル数 (「モデル」「用途」の 2 列)。**この列数ちょうど**を要求することで、
 * GFM のエスケープ済み `\|` (セル境界ではない) を検査が誤ってセル境界として数える経路を
 * 実質的に閉じる — `\|` を含む行は素朴な `split("|")` ではセル数が 2 からずれるため、
 * 汎用の GFM エスケープ解釈を実装しなくても壊れた表として弾ける。
 */
const CANON_TABLE_CELL_COUNT = 2;

/** 行を `|` で割ったセル列に直す (先頭・末尾の `|` は任意なので剥がしてから割る)。 */
const parseTableCells = (rowText: string): readonly string[] =>
    rowText
        .trim()
        .replace(/^\|/, "")
        .replace(/\|\s*$/, "")
        .split("|")
        .map((c) => c.trim());

/**
 * ヘッダー行と区切り行が**ともに正本の列数 (2 列) ちょうど**を持ち、
 * 区切り行の各セルが `TABLE_DELIMITER_CELL` に一致するかを見る。
 * セル数を見ないと、ヘッダーが 2 列なのに区切り行が 3 列という壊れた表でも
 * 「区切り行らしき行がある」というだけで表として受理してしまう。
 * 列数を**正本の実際の列数に固定**するのは、汎用 GFM パーサー (エスケープ・
 * 圧縮空白等) を実装せずに「セル境界に見えるが実は違う」書き方を締め出すためである。
 */
const isValidDelimiterRow = (headerText: string, delimiterText: string): boolean => {
    const headerCells = parseTableCells(headerText);
    const delimiterCells = parseTableCells(delimiterText);
    if (
        headerCells.length !== CANON_TABLE_CELL_COUNT
        || delimiterCells.length !== CANON_TABLE_CELL_COUNT
    ) {
        return false;
    }
    return delimiterCells.every((cell) => TABLE_DELIMITER_CELL.test(cell));
};

interface ScannedFile {
    readonly path: string;
    readonly content: string;
}

interface Violation {
    readonly path: string;
    readonly line: number;
    readonly message: string;
}

interface TokenHit {
    readonly token: string;
    readonly line: number;
    /** 行頭からの 0 始まりの位置 (例外目録との照合に使う)。 */
    readonly column: number;
    /** 候補が現れた行の全文 (例外目録との照合に使う)。 */
    readonly lineText: string;
    readonly accepted: boolean;
}

interface Assignment {
    readonly label: string;
    readonly model: string;
    readonly line: number;
}

interface ParseDiagnostic {
    readonly line: number;
    readonly reason: string;
}

interface AssignmentCollection {
    readonly assignments: readonly Assignment[];
    readonly diagnostics: readonly ParseDiagnostic[];
}

interface CanonTableCollection {
    readonly models: readonly string[];
    readonly diagnostics: readonly ParseDiagnostic[];
}

interface Heading {
    readonly index: number;
    readonly level: number;
    readonly text: string;
}

/** 違反はパス → 行 → 内容の順に並べ替える (失敗ログを走査順に依存させない)。 */
const formatViolations = (violations: readonly Violation[]): readonly string[] =>
    [...violations]
        .sort((a, b) => {
            if (a.path !== b.path) return a.path < b.path ? -1 : 1;
            if (a.line !== b.line) return a.line - b.line;
            if (a.message === b.message) return 0;
            return a.message < b.message ? -1 : 1;
        })
        .map((v) => `${v.path}:${String(v.line)}: ${v.message}`);

/**
 * コードフェンス (``` または ~~~ で囲まれた区間) に属する行に印を付ける。
 * 開始と終了は記号の種類を対応させる (バッククォートで開いた区間はチルダで閉じない)。
 * フェンスの区切り行そのものも「中」として扱う (見出し・宣言・表の行にはなり得ない)。
 */
const computeFenceMask = (lines: readonly string[]): readonly boolean[] => {
    const mask: boolean[] = [];
    let openMarker = "";
    let openLength = 0;
    for (const line of lines) {
        if (openMarker === "") {
            const opener = parseFenceOpen(line);
            if (opener !== null) {
                openMarker = opener.marker;
                openLength = opener.length;
                mask.push(true);
                continue;
            }
            mask.push(false);
            continue;
        }
        mask.push(true);
        const closer = FENCE_CLOSE.exec(line);
        if (closer !== null) {
            const run = closer[1] ?? "";
            if (run.charAt(0) === openMarker && run.length >= openLength) {
                openMarker = "";
                openLength = 0;
            }
        }
    }
    return mask;
};

/** フェンスの外にある見出し行だけを集める。 */
const collectHeadings = (
    lines: readonly string[],
    mask: readonly boolean[],
): readonly Heading[] => {
    const found: Heading[] = [];
    lines.forEach((line, index) => {
        if (mask[index] === true) return;
        const matched = HEADING_PATTERN.exec(line);
        if (matched === null) return;
        const hashes = matched[1] ?? "";
        found.push({
            index,
            level: hashes.length,
            text: line.slice(hashes.length).trim(),
        });
    });
    return found;
};

const isCanonical = (token: string): boolean =>
    (CANONICAL_MODELS as readonly string[]).includes(token);

/**
 * 層 1 の収集器。**フェンスの中も見る** (見本の中に綴りを隠して逃げる経路を作らない。
 * 構造の解析だけがフェンスを避けるという非対称は意図したものである)。
 */
const collectModelTokens = (content: string): readonly TokenHit[] => {
    const hits: TokenHit[] = [];
    content.split(/\r?\n/).forEach((line, index) => {
        for (const match of line.matchAll(MODEL_CANDIDATE_PATTERN)) {
            const token = match[0];
            const at = match.index ?? 0;
            const before = at === 0 ? "" : line.charAt(at - 1);
            hits.push({
                token,
                line: index + 1,
                column: at,
                lineText: line,
                accepted: isCanonical(token) && !IDENTIFIER_CHAR.test(before),
            });
        }
    });
    return hits;
};

/**
 * 層 2 の収集器。見出しで節に切り、節ごとにモデル宣言とラベル宣言の対を取る。
 * **パスを知らないので label までを返す** (複合キーの組み立ては判定器の責務)。
 *
 * 1 つの節が持てる宣言はどちらも 0 個か 1 個である。それ以外 (片方だけ /
 * 2 個以上 / バッククォートで囲まれていない) は **読めない書き方**として診断に入れる
 * (「読めなかったから緑」を構造的に無くす)。
 */
const collectAssignments = (content: string): AssignmentCollection => {
    const lines = content.split(/\r?\n/);
    const mask = computeFenceMask(lines);
    const headings = collectHeadings(lines, mask);

    // 見出し行から次の見出し行の直前までが 1 節 (レベルは問わない)。
    // 最初の見出しより前は「前文の節」として同じ規則で扱う。
    const sections: { readonly start: number; readonly end: number }[] = [];
    let cursor = 0;
    for (const heading of headings) {
        sections.push({ start: cursor, end: heading.index });
        cursor = heading.index;
    }
    sections.push({ start: cursor, end: lines.length });

    const assignments: Assignment[] = [];
    const diagnostics: ParseDiagnostic[] = [];

    for (const section of sections) {
        const models: { value: string | null; line: number }[] = [];
        const labels: { value: string | null; line: number }[] = [];
        for (let i = section.start; i < section.end; i += 1) {
            if (mask[i] === true) continue;
            const line = lines[i] ?? "";
            if (MODEL_DECL_HEAD.test(line)) {
                const matched = MODEL_DECL_STRICT.exec(line);
                models.push({
                    value: matched === null ? null : (matched[1] ?? null),
                    line: i + 1,
                });
            }
            if (LABEL_DECL_HEAD.test(line)) {
                const matched = LABEL_DECL_STRICT.exec(line);
                labels.push({
                    value: matched === null ? null : (matched[1] ?? null),
                    line: i + 1,
                });
            }
        }
        if (models.length === 0 && labels.length === 0) continue;

        const anchor = models.length > 0 ? models[0].line : labels[0].line;
        if (models.length !== 1 || labels.length !== 1) {
            diagnostics.push({
                line: anchor,
                reason:
                    `節のモデル宣言とラベル宣言が対になっていません `
                    + `(model 宣言 ${String(models.length)} 個 / label 宣言 ${String(labels.length)} 個。`
                    + `対にできない書き方は読めない書き方として赤にする)`,
            });
            continue;
        }

        const model = models[0];
        const label = labels[0];
        if (model.value === null) {
            diagnostics.push({
                line: model.line,
                reason: "model 宣言の書式が読めません (値はバッククォートで囲んだ 1 語で書く)",
            });
        }
        if (label.value === null) {
            diagnostics.push({
                line: label.line,
                reason: "label 宣言の書式が読めません (値はバッククォートで囲んだ 1 語で書く)",
            });
        }
        if (model.value === null || label.value === null) continue;

        assignments.push({ label: label.value, model: model.value, line: model.line });
    }

    return { assignments, diagnostics };
};

/**
 * 層 3 の収集器。`## 利用可能モデル` の節の中の表だけを解析する。
 * 節の終端は **次のレベル 1 または 2 の見出し**である (`###` 以下は節を切らない)。
 */
const collectCanonTableModels = (content: string): CanonTableCollection => {
    const lines = content.split(/\r?\n/);
    const mask = computeFenceMask(lines);
    const headings = collectHeadings(lines, mask);
    const targets = headings.filter(
        (h) => h.level === 2 && h.text === CANON_HEADING,
    );
    if (targets.length !== 1) {
        return {
            models: [],
            diagnostics: [
                {
                    line: 0,
                    reason:
                        `正本の見出し "## ${CANON_HEADING}" がちょうど 1 つではありません `
                        + `(実測 ${String(targets.length)} 個)`,
                },
            ],
        };
    }

    const head = targets[0];
    const after = headings.filter((h) => h.index > head.index && h.level <= 2);
    const end = after.length > 0 ? after[0].index : lines.length;

    // 表は「見出し行 + **直後の**区切り行」で始まる **連続した** 行の塊 1 つとして読む。
    // 節の中の `|` 始まりの行をかき集める形だと、間に通常文が挟まって表が壊れていても
    // 正典 3 行が節のどこかにあれば合格してしまう (実在しない表で緑になる経路)。
    const isTableLine = (index: number): boolean =>
        mask[index] !== true && (lines[index] ?? "").trim().startsWith("|");

    const starts: number[] = [];
    for (let i = head.index + 1; i + 1 < end; i += 1) {
        if (!isTableLine(i) || !isTableLine(i + 1)) continue;
        if (!isValidDelimiterRow(lines[i] ?? "", lines[i + 1] ?? "")) continue;
        starts.push(i);
    }
    if (starts.length !== 1) {
        return {
            models: [],
            diagnostics: [
                {
                    line: head.index + 1,
                    reason:
                        `正本の節に表 (見出し行 + 直後の区切り行) がちょうど 1 つありません `
                        + `(実測 ${String(starts.length)} 個)`,
                },
            ],
        };
    }

    const rows: { readonly text: string; readonly line: number }[] = [];
    for (let i = starts[0]; i < end && isTableLine(i); i += 1) {
        rows.push({ text: (lines[i] ?? "").trim(), line: i + 1 });
    }

    const models: string[] = [];
    const diagnostics: ParseDiagnostic[] = [];
    for (const row of rows.slice(2)) {
        const cells = row.text.replace(/^\|/, "").split("|");
        const matched = FIRST_CELL_MODEL.exec(cells[0] ?? "");
        if (matched === null) {
            diagnostics.push({
                line: row.line,
                reason: `表の 1 列目がバッククォートで囲んだ綴り 1 語ではありません: ${row.text}`,
            });
            continue;
        }
        models.push(matched[1] ?? "");
    }
    return { models, diagnostics };
};

/**
 * 例外目録の集計キー。**書き込みと読み出しで必ず同じ関数を通す**
 * (キーの組み立てを 2 か所に書くと、区切り文字が食い違ったときに
 *  「例外が 1 件も数えられていない」という形で静かに壊れる)。
 */
const exemptionKey = (filePath: string, literal: string): string =>
    `${filePath}::${literal}`;

/**
 * 候補が例外目録の文字列の出現に **丸ごと** 覆われているか。
 * 開始位置だけを見ると、登録文字列の直後へ綴りを継ぎ足した
 * `openai.chatgpt-gpt-beta` のような書き方が例外として握り潰されてしまう。
 */
const isCoveredByLiteral = (hit: TokenHit, literal: string): boolean => {
    const end = hit.column + hit.token.length;
    let from = 0;
    for (;;) {
        const at = hit.lineText.indexOf(literal, from);
        if (at === -1) return false;
        if (at <= hit.column && end <= at + literal.length) return true;
        from = at + 1;
    }
};

/** 層 1 の判定器。許可外の綴りと、期待表・例外目録との件数の食い違いを返す。 */
const validateOccurrences = (
    files: readonly ScannedFile[],
    expected: Readonly<
        Record<string, Readonly<Partial<Record<CanonicalModel, number>>>>
    > = EXPECTED_OCCURRENCES,
): readonly string[] => {
    const violations: Violation[] = [];
    const exemptHits = new Map<string, number>();
    const scannedPaths = new Set(files.map((f) => f.path));

    for (const file of files) {
        const exemptions = NON_MODEL_EXEMPTIONS.filter((e) => e.path === file.path);
        const counts = new Map<string, number>();
        for (const hit of collectModelTokens(file.content)) {
            if (!hit.accepted) {
                const covering = exemptions.find((e) =>
                    isCoveredByLiteral(hit, e.literal),
                );
                if (covering !== undefined) {
                    const key = exemptionKey(covering.path, covering.literal);
                    exemptHits.set(key, (exemptHits.get(key) ?? 0) + 1);
                    continue;
                }
                violations.push({
                    path: file.path,
                    line: hit.line,
                    message:
                        `正典に無いモデル名 ${hit.token} が現れています `
                        + `(許可: ${CANONICAL_MODELS.join(" / ")}。裁定 AG-186)`,
                });
                continue;
            }
            counts.set(hit.token, (counts.get(hit.token) ?? 0) + 1);
        }
        const table = expected[file.path] ?? {};
        for (const model of CANONICAL_MODELS) {
            const want = table[model] ?? 0;
            const got = counts.get(model) ?? 0;
            if (want === got) continue;
            violations.push({
                path: file.path,
                line: 0,
                message:
                    `${model} の出現回数が期待表と違います `
                    + `(期待 ${String(want)} 件 / 実測 ${String(got)} 件)`,
            });
        }
    }

    // 例外目録の件数は完全一致で pin する (増えても減っても赤)。
    // 走査に含まれないパスの例外は数えない (単独ファイルの検体を成立させるため。
    // 例外パスが実在することは別の検査項目が固定する)。
    for (const exemption of NON_MODEL_EXEMPTIONS) {
        if (!scannedPaths.has(exemption.path)) continue;
        const got = exemptHits.get(exemptionKey(exemption.path, exemption.literal)) ?? 0;
        if (got === exemption.count) continue;
        violations.push({
            path: exemption.path,
            line: 0,
            message:
                `モデル名ではない既知の文字列 "${exemption.literal}" の件数が目録と違います `
                + `(期待 ${String(exemption.count)} 件 / 実測 ${String(got)} 件。`
                + `登録理由: ${exemption.rationale})`,
        });
    }

    return formatViolations(violations);
};

/** 層 2 の判定器。読めない書き方・複合キーの重複・写像の食い違いを返す。 */
const validateAssignments = (
    files: readonly ScannedFile[],
    expected: Readonly<Record<string, CanonicalModel>> = EXPECTED_ASSIGNMENTS,
): readonly string[] => {
    const violations: Violation[] = [];
    const found = new Map<
        string,
        { readonly model: string; readonly path: string; readonly line: number }
    >();

    for (const file of files) {
        const collected = collectAssignments(file.content);
        for (const diagnostic of collected.diagnostics) {
            violations.push({
                path: file.path,
                line: diagnostic.line,
                message: diagnostic.reason,
            });
        }
        for (const assignment of collected.assignments) {
            const key = `${file.path}#${assignment.label}`;
            const previous = found.get(key);
            if (previous !== undefined) {
                violations.push({
                    path: file.path,
                    line: assignment.line,
                    message:
                        `用途割当のキー ${key} が重複しています `
                        + `(${String(previous.line)} 行目と同じキー)`,
                });
                continue;
            }
            found.set(key, {
                model: assignment.model,
                path: file.path,
                line: assignment.line,
            });
        }
    }

    for (const [key, value] of found) {
        const want = expected[key];
        if (want === undefined) {
            violations.push({
                path: value.path,
                line: value.line,
                message: `期待表に無い用途割当 ${key} → ${value.model} があります`,
            });
            continue;
        }
        if (want === value.model) continue;
        violations.push({
            path: value.path,
            line: value.line,
            message: `用途割当が期待と違います ${key}: 期待 ${want} / 実測 ${value.model}`,
        });
    }

    for (const key of Object.keys(expected)) {
        if (found.has(key)) continue;
        violations.push({
            path: key.split("#")[0] ?? key,
            line: 0,
            message: `期待していた用途割当 ${key} が見つかりません`,
        });
    }

    return formatViolations(violations);
};

/** 層 3 の判定器。正本の表が正典 3 綴りちょうどであることを見る。 */
const validateCanonTable = (content: string): readonly string[] => {
    const collected = collectCanonTableModels(content);
    const violations: Violation[] = collected.diagnostics.map((diagnostic) => ({
        path: CANON_FILE,
        line: diagnostic.line,
        message: diagnostic.reason,
    }));

    if (collected.models.length !== CANONICAL_MODELS.length) {
        violations.push({
            path: CANON_FILE,
            line: 0,
            message:
                `正本の表の行数が違います `
                + `(期待 ${String(CANONICAL_MODELS.length)} 行 / 実測 ${String(collected.models.length)} 行)`,
        });
    }

    const got = [...collected.models].sort().join(" / ");
    const want = [...CANONICAL_MODELS].sort().join(" / ");
    if (got !== want) {
        violations.push({
            path: CANON_FILE,
            line: 0,
            message: `正本の表の綴りが正典と一致しません (期待 ${want} / 実測 ${got})`,
        });
    }

    return formatViolations(violations);
};

/**
 * 走査対象を git 追跡下から列挙する。
 * git 不在は環境不備であり、silent skip せず明瞭に fail させる。
 */
const listScanFiles = (): readonly string[] => {
    let raw: string;
    try {
        raw = execFileSync("git", ["ls-files", "-z", "--", ...SCAN_ROOTS], {
            cwd: REPO_ROOT,
            encoding: "utf8",
            maxBuffer: 64 * 1024 * 1024,
        });
    } catch (e) {
        throw new Error(
            `git ls-files の実行に失敗 (git worktree 前提の architecture invariant): ${String(e)}`,
        );
    }
    return raw.split("\0").filter((p) => p !== "");
};

const readScanFiles = async (): Promise<readonly ScannedFile[]> => {
    const files: ScannedFile[] = [];
    for (const rel of listScanFiles()) {
        let content: string;
        try {
            content = await fs.readFile(path.join(REPO_ROOT, rel), "utf8");
        } catch (e) {
            throw new Error(`走査対象の読込に失敗しました (${rel}): ${String(e)}`);
        }
        files.push({ path: rel, content });
    }
    return files;
};

// ---------------------------------------------------------------------------
// コントロール検体 (実ファイル検査と **同じ判定器** を呼ぶ)
// ---------------------------------------------------------------------------

const l1 = (content: string, filePath: string = REVIEW_PATH): readonly string[] =>
    validateOccurrences([{ path: filePath, content }]);

const l2 = (
    content: string,
    expected: Readonly<Record<string, CanonicalModel>>,
    filePath: string = IMPL_PATH,
): readonly string[] => validateAssignments([{ path: filePath, content }], expected);

const IMPL_EXPECTED: Readonly<Record<string, CanonicalModel>> = {
    [`${IMPL_PATH}#impl-review`]: "gpt-5.6-sol",
};

const DESIGN_EXPECTED: Readonly<Record<string, CanonicalModel>> = {
    [`${DESIGN_PATH}#conceptual-review`]: "gpt-5.6-terra",
    [`${DESIGN_PATH}#design-review`]: "gpt-5.6-sol",
};

const FENCE = "```";
const ROW_SOL = "| `gpt-5.6-sol` | コードの分析・レビュー・技術設計 |";
const ROW_TERRA = "| `gpt-5.6-terra` | 議論・概念設計 |";
const ROW_LUNA = "| `gpt-5.6-luna` | 軽い判定 |";

/** 正本の体裁を持つ文書を組み立てる (検体の土台)。 */
const canonDoc = (rows: readonly string[], inSection: readonly string[] = []): string =>
    [
        "# 表題",
        "",
        `## ${CANON_HEADING}`,
        "",
        "| モデル | 用途 |",
        "|--------|------|",
        ...rows,
        "",
        ...inSection,
        "## 次の節",
        "",
        "本文。",
        "",
    ].join("\n");

interface Specimen {
    readonly id: string;
    readonly title: string;
    readonly run: () => readonly string[];
    /**
     * 点灯した違反の**どれか 1 件がこの部分文字列を含む**ことまで要求する (任意)。
     * 「違反が空でない」だけの検査では、意図した診断経路とは別の違反
     * (例えば期待割当が見つからない、という別の理由の違反) が代わりに点灯しても
     * テストは緑のままになってしまう検体がある。そのような検体では、判定器が
     * **狙った理由で**点灯したことをメッセージの内容まで見て固定する。
     */
    readonly expectSubstring?: string;
}

/** 負のコントロール (判定器が実際に点灯することを 1 検体 1 テストで確かめる)。 */
const NEGATIVE_SPECIMENS: readonly Specimen[] = [
    {
        id: "N-01",
        title: "旧世代の綴りを含む行",
        run: () => l1("既定は `gpt-5.6-sol`\n旧世代の `gpt-5.4-mini` は使わない\n"),
    },
    {
        id: "N-02",
        title: "接尾辞を落とした綴り",
        run: () => l1("既定は `gpt-5.6-sol`\n接尾辞を落とした gpt-5.6 は書かない\n"),
    },
    {
        id: "N-03",
        title: "直前に文字が続く綴り",
        run: () => l1("既定は `gpt-5.6-sol`\nxgpt-5.6-sol\n"),
    },
    {
        id: "N-04",
        title: "後ろに続く綴り",
        run: () => l1("既定は `gpt-5.6-sol`\ngpt-5.6-sol_preview\n"),
    },
    {
        id: "N-05",
        title: "末尾に区切りが残る綴り",
        run: () => l1("既定は `gpt-5.6-sol`\ngpt-5.6-sol-\n"),
    },
    {
        id: "N-06",
        title: "大文字の綴り",
        run: () => l1("既定は `gpt-5.6-sol`\nGPT-5.6-SOL\n"),
    },
    {
        id: "N-07",
        title: "期待より 1 件多い出現",
        run: () => l1("既定は `gpt-5.6-sol`\nもう一度 `gpt-5.6-sol`\n"),
    },
    {
        id: "N-08",
        title: "期待より 1 件少ない出現",
        run: () => l1("モデル名を 1 つも書かない本文\n"),
    },
    {
        id: "N-09",
        title: "期待表に無いパスに正典綴りがある",
        run: () => l1("`gpt-5.6-sol`\n", ".claude/skills/app-todo-add/SKILL.md"),
    },
    {
        id: "N-10",
        title: "コードフェンスの中に許可外の綴りがある",
        run: () =>
            l1(["既定は `gpt-5.6-sol`", "", FENCE, "gpt-5.4-mini", FENCE, ""].join("\n")),
    },
    {
        id: "N-11",
        title: "節にモデル宣言があってラベル宣言が無い",
        // expected を空にして「期待割当が見つからない」という別理由の違反を混入させない
        // (診断メッセージそのものが点灯したことを見る。他の検体と区別するための対応)。
        run: () =>
            l2(["### レビュー", "", "**model**: `gpt-5.6-sol`", ""].join("\n"), {}),
        expectSubstring: "対になっていません",
    },
    {
        id: "N-12",
        title: "節にラベル宣言があってモデル宣言が無い",
        run: () => l2(["### レビュー", "", "**label**: `impl-review`", ""].join("\n"), {}),
        expectSubstring: "対になっていません",
    },
    {
        id: "N-13",
        title: "同じ節にモデル宣言が 2 個",
        run: () =>
            l2(
                [
                    "### レビュー",
                    "",
                    "**model**: `gpt-5.6-sol`",
                    "**model**: `gpt-5.6-terra`",
                    "**label**: `impl-review`",
                    "",
                ].join("\n"),
                {},
            ),
        expectSubstring: "対になっていません",
    },
    {
        id: "N-14",
        title: "バッククォートで囲まれていない宣言",
        run: () =>
            l2(
                [
                    "### レビュー",
                    "",
                    "**model**: gpt-5.6-sol",
                    "**label**: `impl-review`",
                    "",
                ].join("\n"),
                {},
            ),
        expectSubstring: "書式が読めません",
    },
    {
        id: "N-15",
        title: "用途と綴りの対応が期待と違う (入れ替え)",
        run: () =>
            l2(
                [
                    "### 1-3. 概念設計レビュー",
                    "",
                    "**model**: `gpt-5.6-sol`",
                    "**label**: `conceptual-review`",
                    "",
                    "### 2-3. 詳細設計レビュー",
                    "",
                    "**model**: `gpt-5.6-terra`",
                    "**label**: `design-review`",
                    "",
                ].join("\n"),
                DESIGN_EXPECTED,
                DESIGN_PATH,
            ),
    },
    {
        id: "N-16",
        title: "期待していた割当が消えた",
        run: () =>
            l2(
                [
                    "### 1-3. 概念設計レビュー",
                    "",
                    "**model**: `gpt-5.6-terra`",
                    "**label**: `conceptual-review`",
                    "",
                ].join("\n"),
                DESIGN_EXPECTED,
                DESIGN_PATH,
            ),
    },
    {
        id: "N-17",
        title: "未知の割当キーが増えた",
        run: () =>
            l2(
                [
                    "### A-2. 実装レビュー",
                    "",
                    "**model**: `gpt-5.6-sol`",
                    "**label**: `impl-review`",
                    "",
                    "### A-4. 追加のレビュー",
                    "",
                    "**model**: `gpt-5.6-sol`",
                    "**label**: `extra-review`",
                    "",
                ].join("\n"),
                IMPL_EXPECTED,
            ),
    },
    {
        id: "N-18",
        title: "同じ複合キーが 2 回現れた",
        run: () =>
            l2(
                [
                    "### A-2. 実装レビュー",
                    "",
                    "**model**: `gpt-5.6-sol`",
                    "**label**: `impl-review`",
                    "",
                    "### A-5. 再レビュー",
                    "",
                    "**model**: `gpt-5.6-sol`",
                    "**label**: `impl-review`",
                    "",
                ].join("\n"),
                IMPL_EXPECTED,
            ),
    },
    {
        id: "N-19",
        title: "正本の表が 2 行しかない",
        run: () => validateCanonTable(canonDoc([ROW_SOL, ROW_TERRA])),
    },
    {
        id: "N-20",
        title: "正本の表が 4 行ある",
        run: () => validateCanonTable(canonDoc([ROW_SOL, ROW_TERRA, ROW_LUNA, ROW_SOL])),
    },
    {
        id: "N-21",
        title: "正本の見出しが 2 つある",
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "| モデル | 用途 |",
                    "|--------|------|",
                    ROW_SOL,
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "本文。",
                    "",
                ].join("\n"),
            ),
    },
    {
        id: "N-22",
        title: "正本の見出しが 1 つも無い",
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    "## モデルの話",
                    "",
                    "| モデル | 用途 |",
                    "|--------|------|",
                    ROW_SOL,
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                ].join("\n"),
            ),
    },
    {
        id: "N-23",
        title: "表が正本の節の外へ移動した",
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "説明だけがある。",
                    "",
                    "## 別の節",
                    "",
                    "| モデル | 用途 |",
                    "|--------|------|",
                    ROW_SOL,
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                ].join("\n"),
            ),
    },
    {
        id: "N-24",
        title: "`gpt-` の直後が数字でない綴り",
        run: () => l1("既定は `gpt-5.6-sol`\ngpt-beta\n"),
    },
    {
        id: "N-25",
        title: "例外目録の文字列の件数が目録と違う",
        run: () =>
            validateOccurrences([
                {
                    path: "scripts/codex",
                    content: [
                        "openai.chatgpt-",
                        "openai.chatgpt-",
                        "openai.chatgpt-",
                        "openai.chatgpt-",
                        "openai\\.chatgpt-",
                        "",
                    ].join("\n"),
                },
            ]),
    },
    {
        id: "N-26",
        title: "正本の表の途中に通常文が挟まる",
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "| モデル | 用途 |",
                    "|--------|------|",
                    ROW_SOL,
                    "表の途中に混ざった通常文。",
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                    "## 次の節",
                    "",
                ].join("\n"),
            ),
    },
    {
        id: "N-27",
        title: "正本の節に表が 2 つある",
        run: () =>
            validateCanonTable(
                canonDoc(
                    [ROW_SOL, ROW_TERRA, ROW_LUNA],
                    [
                        "| モデル | 用途 |",
                        "|--------|------|",
                        ROW_SOL,
                        ROW_TERRA,
                        ROW_LUNA,
                        "",
                    ],
                ),
            ),
    },
    {
        id: "N-28",
        title: "例外目録の文字列の直後へ綴りを継ぎ足した形",
        run: () =>
            validateOccurrences([
                {
                    path: "scripts/codex",
                    content: [
                        "openai.chatgpt-gpt-beta",
                        "openai.chatgpt-",
                        "openai.chatgpt-",
                        "openai\\.chatgpt-",
                        "",
                    ].join("\n"),
                },
            ]),
    },
    {
        id: "N-29",
        title: "区切りセルがハイフン 1 文字しかない (GFM の区切り行として弱すぎる)",
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "| モデル | 用途 |",
                    "|-|-|",
                    ROW_SOL,
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                    "## 次の節",
                    "",
                ].join("\n"),
            ),
    },
    {
        id: "N-30",
        title: "ヘッダーと区切り行のセル数が食い違う",
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "| モデル | 用途 |",
                    "|--------|------|------|",
                    ROW_SOL,
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                    "## 次の節",
                    "",
                ].join("\n"),
            ),
    },
    {
        id: "N-31",
        title: "ヘッダーにエスケープ済み `\\|` があり素朴な分割ではセル数がずれる",
        // ヘッダー「| モ\|デル | 用途 |」は GFM 上 2 セルだが、`|` を境界とする
        // 素朴な分割では 3 セルに見える。区切り行も 3 セルに揃えれば
        // 「セル数が一致している」という条件だけでは表として受理されてしまう
        // (正本のセル数 2 ちょうどを要求することで締め出す)。
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "| モ\\|デル | 用途 |",
                    "|---|---|---|",
                    ROW_SOL,
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                    "## 次の節",
                    "",
                ].join("\n"),
            ),
    },
    {
        id: "N-32",
        title: "4 スペース字下げの偽フェンスで正本の見出し・表を隠そうとする",
        // CommonMark はタブ・4 スペース以上のインデントをフェンス開始と認めない
        // (indented code block になる)。フェンス開始の判定を字下げ無制限にすると、
        // 「4 スペース字下げの ``` 」を検査だけがフェンス開始と誤読し、
        // その直後にある本物の見出しを隠したまま、後方の裸の ``` を検査だけが
        // フェンス終了と誤読して「本物に見える」後続構造を正本として受理してしまう。
        // 字下げを 0〜3 スペースに限定した後は、直後の裸の ``` が実際のフェンス開始になり、
        // その内側にある本物の見出し・表が隠れて「表が見つからない」違反になるべきである。
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    "    ```",
                    `## ${CANON_HEADING}`,
                    "説明だけ。",
                    "```",
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "| モデル | 用途 |",
                    "|--------|------|",
                    ROW_SOL,
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                ].join("\n"),
            ),
    },
];

/**
 * 正のコントロール (誤って点灯しないことを確かめる)。
 * 負の検体だけでは「フェンスの中を実体として読む誤実装」と
 * 「`###` で層 3 の節を終える誤実装」が現物でも検体でも緑になりうる。
 */
const POSITIVE_SPECIMENS: readonly Specimen[] = [
    {
        id: "P-01",
        title: "コードフェンスの中のモデル宣言とラベル宣言は割当にしない",
        run: () =>
            l2(
                [
                    "### レビュー",
                    "",
                    `${FENCE}markdown`,
                    "**model**: `gpt-5.6-sol`",
                    "**label**: `impl-review`",
                    FENCE,
                    "",
                ].join("\n"),
                {},
            ),
    },
    {
        id: "P-02",
        title: "合格の正本にフェンス内の偽の見出しを足しても見出し数が増えない",
        run: () =>
            validateCanonTable(
                canonDoc(
                    [ROW_SOL, ROW_TERRA, ROW_LUNA],
                    [`${FENCE}markdown`, `## ${CANON_HEADING}`, FENCE, ""],
                ),
            ),
    },
    {
        id: "P-03",
        title: "合格の正本にフェンス内の偽の表を足しても正本の表として読まない",
        run: () =>
            validateCanonTable(
                canonDoc(
                    [ROW_SOL, ROW_TERRA, ROW_LUNA],
                    [
                        `${FENCE}markdown`,
                        "| モデル | 用途 |",
                        "|--------|------|",
                        "| `gpt-5.4-mini` | 見本 |",
                        FENCE,
                        "",
                    ],
                ),
            ),
    },
    {
        id: "P-04",
        title: "`###` の小見出しは層 3 の節を終えない",
        run: () =>
            validateCanonTable(
                [
                    "# 表題",
                    "",
                    `## ${CANON_HEADING}`,
                    "",
                    "### 補足",
                    "",
                    "| モデル | 用途 |",
                    "|--------|------|",
                    ROW_SOL,
                    ROW_TERRA,
                    ROW_LUNA,
                    "",
                    "## 次の節",
                    "",
                ].join("\n"),
            ),
    },
    {
        id: "P-05",
        title: "例外目録に登録した既知の文字列は違反にならない",
        run: () =>
            validateOccurrences([
                {
                    path: "scripts/codex",
                    content: [
                        '"$HOME/.vscode/extensions/openai.chatgpt-"*"-$PLATFORM" \\',
                        '"$HOME/.vscode-server/extensions/openai.chatgpt-"*"-$PLATFORM" \\',
                        "  | sed 's|.*/openai\\.chatgpt-||' \\",
                        '    cand="$root/openai.chatgpt-$LATEST_EXT"',
                        "",
                    ].join("\n"),
                },
            ]),
    },
];

describe("codex model consistency", () => {
    it("走査対象の列挙が成立する (git 追跡下・0 件は赤)", () => {
        const files = listScanFiles();
        expect(
            files.length,
            `走査対象が 0 件です。走査根 (${SCAN_ROOTS.join(" / ")}) の指定か `
                + `git 追跡状態を確認してください (空振りを合格と読まない)。`,
        ).toBeGreaterThan(0);
    });

    it("期待表のキーが全て実在する (移動・改名・削除で守備範囲が痩せない)", () => {
        const files = new Set(listScanFiles());
        const wanted = [
            ...Object.keys(EXPECTED_OCCURRENCES),
            ...Object.keys(EXPECTED_ASSIGNMENTS).map((k) => k.split("#")[0] ?? k),
            ...NON_MODEL_EXEMPTIONS.map((e) => e.path),
            CANON_FILE,
        ];
        const missing = [...new Set(wanted)].filter((p) => !files.has(p)).sort();
        expect(
            missing,
            `期待表が指すファイルが走査対象に実在しません。移動・改名したなら `
                + `期待表 (EXPECTED_OCCURRENCES / EXPECTED_ASSIGNMENTS / CANON_FILE) も `
                + `同じ変更で直してください:\n  ${missing.join("\n  ")}`,
        ).toEqual([]);
    });

    it("層 1: 正典 3 綴り以外が現れず、出現回数が期待表と一致する", async () => {
        const files = await readScanFiles();
        expect(files.length).toBeGreaterThan(0);
        expect(
            validateOccurrences(files),
            `Codex に指定するモデルの正典は裁定 AG-186 の 3 綴り `
                + `(${CANONICAL_MODELS.join(" / ")}) である。綴りを変えるときは `
                + `正本 (${CANON_FILE}) と本テストの期待表を同じ変更で直すこと。`,
        ).toEqual([]);
    });

    it("層 2: 用途と綴りの対応が期待写像と完全一致する", async () => {
        const files = await readScanFiles();
        expect(
            validateAssignments(files),
            `用途 (label) と綴りの対応が期待と食い違っています。`
                + `割当の正本は ${CANON_FILE} の「${CANON_HEADING}」節である。`,
        ).toEqual([]);
    });

    it("層 3: 正本の表が正典 3 綴りちょうどを持つ", async () => {
        const files = await readScanFiles();
        const canon = files.find((f) => f.path === CANON_FILE);
        expect(canon, `正本 ${CANON_FILE} が走査対象にありません`).toBeDefined();
        expect(
            validateCanonTable(canon?.content ?? ""),
            `正本の「${CANON_HEADING}」表が正典と一致しません。`,
        ).toEqual([]);
    });

    it.each(NEGATIVE_SPECIMENS)(
        "負のコントロール $id: $title",
        ({ run, expectSubstring }: Specimen) => {
            const violations = run();
            expect(
                violations,
                "判定器が点灯しませんでした (空振りを合格として扱わないための検体)",
            ).not.toEqual([]);
            if (expectSubstring !== undefined) {
                expect(
                    violations.some((v) => v.includes(expectSubstring)),
                    `違反は点灯したが、狙った診断理由 "${expectSubstring}" を含む違反が `
                        + `1 件もありません (意図と別の理由で点灯していないかの確認): `
                        + `${violations.join(" / ")}`,
                ).toBe(true);
            }
        },
    );

    it.each(POSITIVE_SPECIMENS)(
        "正のコントロール $id: $title",
        ({ run }: Specimen) => {
            expect(run(), "点灯すべきでない検体で判定器が点灯しました").toEqual([]);
        },
    );
});
