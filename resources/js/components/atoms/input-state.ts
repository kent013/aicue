/**
 * 入力系 atom (Input / Textarea / Select) の共通スタイル定義。
 * 見た目の真実は DESIGN.md §Components。変更時は全入力 atom に波及することに注意。
 */

export const INPUT_BASE_CLASSES = [
    "w-full rounded-sm border bg-surface text-body text-text",
    "px-3 py-1.5",
    "transition-colors duration-150",
    "placeholder:text-text-secondary/70",
    "focus:border-primary focus:ring-3 focus:ring-primary/20 focus:outline-none",
    "disabled:cursor-not-allowed disabled:bg-neutral disabled:text-text-secondary",
].join(" ");

/** error の有無で border 色を切り替える */
export function inputStateClass(error: boolean): string {
    return error ? "border-danger ring-3 ring-danger/15" : "border-border-strong";
}
