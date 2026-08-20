/**
 * DOM の textContent を文言比較できる形へ正規化する。
 *
 * Svelte ソースの改行・インデントは textContent に空白として残るため、素の比較では
 * 「文全体の完全一致」を書けない。連続する空白 (改行・タブを含む) を 1 つの半角空白へ
 * 畳んで前後を trim した文字列同士で比較する。
 *
 * **保証しないもの**: 全角空白 (U+3000) と半角空白の違いは畳み込みの対象であり区別しない
 * (`\s` が全角空白に一致するため)。句読点・全角半角の混在は正規化しない
 * (それらは文面の一部として比較対象に残す)。
 */
export function normalizeText(value: string | null | undefined): string {
    return (value ?? "").replace(/\s+/g, " ").trim();
}

/** 要素の textContent を正規化して返す (`normalizeText` の DOM 版)。 */
export function normalizedTextOf(element: Element | null): string {
    return normalizeText(element?.textContent);
}
