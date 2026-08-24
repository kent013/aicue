/**
 * Markdown の**行の分類**を 1 実装へ集約する (正典 i21) — 検査テスト共有。
 *
 * `design-system-docs.test.ts` (docs/design-system.md の構造) と
 * `design-md.ts` の `parseDesignComponentSections()` (DESIGN.md §Components の節) が
 * **同じ実装**を使う。同じ Markdown に 2 本の字句走査ができると弱い方が緑を作る。
 *
 * **契約は 2 つあり、混ぜない**。
 *
 * 【契約 A — 規範判定対象外領域の除去】
 *   呼称は「非描画領域」ではなく**「規範判定対象外領域」**である —
 *   **HTML コメントは読者に描画されない**が、**囲みコードは描画される**。
 *   どちらも「規範の本文として数えない」点だけが共通である。
 *   落とすのは HTML コメントと囲みコードの 2 つ。行数は保存する
 *   (行番号がずれると節の切り出しがずれるため)。
 *
 *   fence の受理範囲 (実装者依存にしない):
 *     marker は**同一文字 3 個以上** (バッククォートまたはチルダ)、開始は**字下げ 3 空白まで**、
 *     終了は**開始と同じ種類で開始以上の長さ・後続は空白のみ**、
 *     バッククォート型は**情報文字列にバッククォートを含められない**
 *     (含む行は開始 fence ではないので通常の本文として扱う。fence 扱いにすると
 *     次に来る本物の開始 fence を終端と誤認して区間が 1 つずれる)。
 *
 *   **囲みコードの外の行に marker (3 個以上連続) が現れたら、その行が上の受理範囲を満たす
 *   正規の top-level fence 行でない限り診断にする**。これで
 *   引用やリストを伴う fence 候補も、行の途中の連続 marker も、
 *   **3 個以上の delimiter の行内コード span** も落ちる。
 *   **container 文法 (list marker の記法・padding・入れ子の順) は 1 つも書かない** —
 *   判定に使うのは「marker より前に非空白があるか」という**位置だけ**である
 *   (`container-fence` という名前はその位置の分類であって、container 文法の解析結果ではない)。
 *
 * 【契約 B — 字下げの禁止】囲みコードの外に次のいずれかがあれば行番号を返す (gate が失敗させる)。
 *   1. **タブを含む行** (列の解釈が環境依存になるため)
 *   2. **4 個以上連続した半角空白を含む行** (行頭に限らない)
 *   **契約 B は container 文法を 1 つも扱わない**。
 *
 *   **見逃しが 0 であることの論証**:
 *     (1) すべての有効な container prefix を消費した後の**内容開始列**を基準にする。
 *     (2) 字下げコードには、その基準から**さらに 4 列以上**の字下げが要る。
 *     (3) タブを禁じた場合、その追加 4 列を作れるのは**連続した U+0020 だけ**である。
 *     (4) list marker の幅や padding は**内容開始列を決める prefix 側**であり、
 *         追加 4 列の代用にはならない。
 *     (5) 全行を見るので、コードブロックの**少なくとも先頭の非空行**で 4 連続空白を検出する。
 *   よって引用の中の字下げも、リストの中の字下げも、番号つきリストの中の字下げも契約 B で落ちる。
 *
 *   i12 の目的 (契約の本文を読者に見えない場所へ退避させられないこと) は、
 *   **そもそも書かせない**ことで満たす。**偽陽性の class は 1 つだけ**である —
 *   本文の中で意図的に 4 空白以上を並べる書き方 (表の桁揃え等)。
 *   **書き方を直す**のが正しい対応であり、検査は緩めない
 *   (拾いすぎる方向へ倒すのは共通規約 (b) に沿う)。
 *
 * **CommonMark パーサは導入しない**: `marked` / `commonmark` / `markdown-it` はいずれも未導入で、
 * この 1 検査のために依存を増やすのは「今必要なものだけ作る」に反する。
 * **導入を再検討する契機**は「対象の文書に字下げコードを書く正当な必要が出たとき」である。
 *
 * 【保証しないもの】HTML 要素による非表示 (`<details>` / `hidden` 属性等) は見ていない。
 * また HTML コメントの除去は**行内コードの文脈を見ない**ので、行内コードとして書いた
 * HTML コメントは読者に見えていても潰される (跡には目印が残るので断片は繋がらない)。
 */

export type MarkdownDiagnosticReason =
    | "unterminated-html-comment"
    | "unterminated-fence"
    /** container prefix を伴う fence 候補 (= marker より前に非空白がある行) */
    | "container-fence"
    /** 受理範囲外の fence 記法 (行頭から始まるが正規の fence ではない) */
    | "unsupported-fence";

export interface MarkdownDiagnostic {
    /** 1 始まりの行番号 (診断出力用。期待値には使わない) */
    readonly line: number;
    readonly reason: MarkdownDiagnosticReason;
}

export interface MarkdownScan {
    /** 規範判定の対象になる行 (HTML コメントと囲みコードを "" へ潰したもの。**行数は保つ**) */
    readonly renderedLines: readonly string[];
    /** 契約 B: 囲みコードの外でタブ、または 4 個以上連続した半角空白を含む行の行番号 (1 始まり) */
    readonly forbiddenIndentLines: readonly number[];
    /** 契約 A: 解析できなかった形 (1 件でもあれば gate が落ちる) */
    readonly diagnostics: readonly MarkdownDiagnostic[];
}

const FENCE_MARKER = /`{3,}|~{3,}/;
const FENCE_OPEN = /^ {0,3}(`{3,}|~{3,})/;
const FENCE_CLOSE = /^ {0,3}(`{3,}|~{3,})[ \t]*$/;
const FORBIDDEN_INDENT = /\t| {4,}/;

/**
 * コメントを取り除いた跡に残す目印。垂直タブ (U+000B) を使う。
 *
 * 要件は 2 つある。
 *   1. **規範の最小断片には使わない文字**であること。半角空白のように断片へ現れる文字だと、
 *      最小断片が元々空白を含む位置 (`同一 PR 内で` の空白等) を狙って断片を合成できてしまう
 *   2. **`trim()` が空白として落とす文字**であること。落とさない文字 (U+0000 等) だと、
 *      コメントだけの行が「本文のある行」に見えて節の非空検査をすり抜ける
 * 垂直タブはこの 2 つを同時に満たす。
 */
export const HIDDEN_MARK = "\u000B";

export function scanMarkdownLines(source: string): MarkdownScan {
    const out: string[] = [];
    const forbiddenIndentLines: number[] = [];
    const diagnostics: MarkdownDiagnostic[] = [];

    let fence: { readonly char: string; readonly length: number; readonly line: number } | null =
        null;
    let inComment = false;
    let commentStartLine = 0;

    const raws = source.split(/\r?\n/);
    for (let index = 0; index < raws.length; index += 1) {
        const raw = raws[index];
        const lineNumber = index + 1;

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
            // コメントの終端より後ろだけを規範判定の対象として残す (跡に目印を置く)
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
                commentStartLine = lineNumber;
                break;
            }
            line = line.slice(0, start) + HIDDEN_MARK + line.slice(end + 3);
        }

        // 契約 B: 囲みコードの外の字下げを禁じる
        if (FORBIDDEN_INDENT.test(line)) forbiddenIndentLines.push(lineNumber);

        const markerIndex = line.search(FENCE_MARKER);
        if (markerIndex >= 0) {
            const open = line.match(FENCE_OPEN);
            // バッククォート fence の情報文字列にバッククォートがある行は開始 fence ではない
            const infoString = open === null ? "" : line.slice(open[0].length);
            const validOpen = open !== null && !(open[1][0] === "`" && infoString.includes("`"));
            if (validOpen && open !== null) {
                fence = { char: open[1][0], length: open[1].length, line: lineNumber };
                out.push("");
                continue;
            }
            // 正規の top-level fence 行でない marker の出現は診断にする。
            // 判定は「marker より前に非空白があるか」という位置だけで、container 文法は解釈しない。
            diagnostics.push({
                line: lineNumber,
                reason: /^\s*$/.test(line.slice(0, markerIndex))
                    ? "unsupported-fence"
                    : "container-fence",
            });
        }

        out.push(line);
    }

    if (fence !== null) diagnostics.push({ line: fence.line, reason: "unterminated-fence" });
    if (inComment) {
        diagnostics.push({ line: commentStartLine, reason: "unterminated-html-comment" });
    }

    return { renderedLines: out, forbiddenIndentLines, diagnostics };
}
