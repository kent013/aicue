/**
 * 入力系 atom (Input / Textarea / Select) の共通スタイル定義。
 * 見た目の真実は DESIGN.md §Components。変更時は全入力 atom に波及することに注意。
 */

// 背景色は inputStateClass 側で確定させる (readonly と競合させないため base に置かない。
// Tailwind は同一プロパティの utility が並んだ場合、勝敗が class 属性の順ではなく
// 生成 CSS の順で決まるため、bg は常に 1 つだけ出力する)。
export const INPUT_BASE_CLASSES = [
    "w-full rounded-sm border text-body text-text",
    "px-3 py-1.5",
    "transition-colors duration-150",
    "placeholder:text-text-secondary/70",
    "focus:border-primary focus:ring-3 focus:ring-primary/20 focus:outline-none",
    "disabled:cursor-not-allowed disabled:bg-neutral disabled:text-text-secondary",
].join(" ");

/**
 * error / readonly の状態クラス。
 *
 * - error: border を danger 化する (readonly でも維持する = どのフィールドが不正か分かる)
 * - readonly: **編集できないことを面で示す**。ただし disabled とは意味が違うので同一にしない —
 *   readonly の値は生きている (送信される・選択してコピーできる・フォーカスできる) ため、
 *   文字色は通常のまま (`text-text`)、カーソルは `cursor-default`、focus ring は base のまま維持する。
 *   disabled は `text-text-secondary` + `cursor-not-allowed` + フォーカス不可 (base の disabled: 側)。
 *   `<select>` は HTML 仕様上 readonly を持たないため呼び出さない (既定 false)。
 */
export function inputStateClass(error: boolean, readonly = false): string {
    const border = error ? "border-danger ring-3 ring-danger/15" : "border-border-strong";
    return readonly ? `${border} bg-neutral cursor-default` : `${border} bg-surface`;
}
