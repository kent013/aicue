/**
 * 日付・時刻フォーマット共通ヘルパ。`Intl.DateTimeFormat` ベースで、
 * 依存追加なしで ja-JP 表示を統一する。
 *
 * 各ページに散在しがちな `toLocaleDateString('ja-JP')` 呼び出しと
 * null/不正値ハンドリングの SSoT。
 */

const DEFAULT_LOCALE = "ja-JP";

const dateFormatter = new Intl.DateTimeFormat(DEFAULT_LOCALE, {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
});

const dateTimeFormatter = new Intl.DateTimeFormat(DEFAULT_LOCALE, {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
});

/** 入力を Date に正規化する。null/undefined/空文字/不正値は null を返す */
function toDate(value: Date | string | number | null | undefined): Date | null {
    if (value === null || value === undefined || value === "") return null;
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * 絶対日付フォーマット (例: "2026/05/04")。不正値は fallback (省略時 "-") を返す。
 */
export function formatDate(
    value: Date | string | number | null | undefined,
    fallback: string = "-",
): string {
    const date = toDate(value);
    return date === null ? fallback : dateFormatter.format(date);
}

/**
 * 絶対日時フォーマット (例: "2026/05/04 10:08")。不正値は fallback (省略時 "-") を返す。
 */
export function formatDateTime(
    value: Date | string | number | null | undefined,
    fallback: string = "-",
): string {
    const date = toDate(value);
    return date === null ? fallback : dateTimeFormatter.format(date);
}
