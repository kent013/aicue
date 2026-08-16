/**
 * リスト並べ替えの純関数 (DOM に触れない)。
 *
 * D&D は DOM イベントの連鎖でテストしづらい。そこで「どこに落ちたら何番目になるか」の
 * 意味論だけをここに閉じ込め、Vitest で網羅する (概念設計 D3)。
 *
 * **index の語彙は 2 つある**。混同すると off-by-one になるため関数を分ける (受け入れ条件 A5):
 * - **挿入 index (insertion index)**: 行と行の隙間を数えた `0..n` の値。n 行のリストには
 *   隙間が n+1 個ある。「どの隙間に落としたか」を表す。
 * - **最終 index (final index)**: 移動が終わった後の配列での `0..n-1` の値。
 *   撮影 PWA がサーバへ渡す `position` はこちらである
 *   (`CaptureTakeService::reorderWithinCut` は対象を除いた配列へ splice するため、
 *   結果として「移動後の全体配列での 0 始まり index」と一致する)。
 */

/** 行の上下位置 (getBoundingClientRect の実測値。viewport 座標) */
export interface RowBounds {
    readonly top: number;
    readonly height: number;
}

/**
 * 要素を from から to (最終 index) へ動かした**新しい配列**を返す。入力は変更しない。
 * 範囲外・非整数は「動かさない」に倒す (fail-safe。呼び出し側で throw させない)。
 *
 * 要素を**値として取り出さず、配列のまま移す**。
 * `const moved = next[from]; if (moved === undefined) return next;` の形は、
 * 配列要素の**値**を存在判定に使うため `T` に `undefined` を含む型では
 * 有効な要素が動かせなくなる (generic の契約と実装が食い違う)。
 * `splice` の戻り値をそのまま spread すれば、`undefined` 要素も正しく動き、
 * 添字アクセスの厳格化設定の有無にも依存しない (design-review R2)。
 * `from` は直前に範囲検査済みなので、戻り値は実行時に必ず 1 要素である。
 */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
    const next = [...list];
    if (!Number.isInteger(from) || !Number.isInteger(to)) return next;
    if (from < 0 || from >= next.length) return next;
    const clamped = Math.min(Math.max(to, 0), next.length - 1);
    if (clamped === from) return next;
    const moved = next.splice(from, 1);
    next.splice(clamped, 0, ...moved);
    return next;
}

/**
 * ポインタの Y 座標 (viewport 座標) から**挿入 index** (0..rows.length) を決める。
 * 各行の中点より上なら「その行の前」、下ならさらに次の行を見る。
 *
 * rows は**表示順**で、掴んでいる行自身も含めて渡す (DOM から抜かないため)。
 * スクロールしても `getBoundingClientRect()` を採り直せば viewport 座標系で
 * ポインタ座標と一致する (受け入れ条件 A2)。
 */
export function insertionIndexFromRects(rows: readonly RowBounds[], pointerY: number): number {
    let index = 0;
    for (const row of rows) {
        if (pointerY < row.top + row.height / 2) return index;
        index += 1;
    }
    return rows.length;
}

/**
 * 挿入 index → 最終 index。
 * 掴んだ行自身がいったんリストから抜けるぶん、掴んだ位置より後ろの隙間は 1 つ手前へ詰まる。
 * 掴んだ行の前後 2 つの隙間 (from と from+1) はどちらも「動かさない」= from になる。
 *
 * **入力契約**: `insertion` は `0..n` (insertionIndexFromRects の出力)、`from` は `0..n-1` の
 * 正規化済みの値を前提とする。範囲外の clamp はここでは行わない
 * (下流の `moveItem` が 1 箇所で clamp する。2 箇所で丸めると意味が分散する)。
 */
export function toFinalIndex(insertion: number, from: number): number {
    return insertion > from ? insertion - 1 : insertion;
}
