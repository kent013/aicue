import type { Snippet } from "svelte";

/**
 * ConfirmDialog organism の仕様の真実。意味論は DESIGN.md §Components > ConfirmDialog を参照。
 */

/**
 * 確認ボタンの variant。2 値に限定する:
 * - primary: 通常の確認 (可逆な操作)
 * - danger: irreversible / destructive な操作 (削除・revoke 等。DESIGN.md §色の意味的割り当てルール)
 */
export type ConfirmVariant = "primary" | "danger";

export interface ConfirmDialogProps {
    /** 開閉状態 (bindable)。呼び出し側が $state で保持し bind:open する */
    open: boolean;
    title: string;
    /** 確認メッセージ本文 */
    message: string;
    /**
     * message の直上に描画する任意スロット (サーバ validation エラーの Alert 等)。
     * 未指定なら描画されない = 既存の出力は不変。
     */
    banner?: Snippet;
    confirmLabel?: string;
    cancelLabel?: string;
    /** 既定 primary。irreversible / destructive な操作は danger を指定する */
    confirmVariant?: ConfirmVariant;
    /** true の間 confirm を loading 表示し、ESC / overlay / cancel での close を抑止する */
    processing?: boolean;
    /** 確認時に呼ばれる。close は呼び出し側の責務 (処理完了後に open=false にする) */
    onConfirm: () => void;
    /** キャンセル・ESC・overlay・X での close 時に呼ばれる */
    onCancel?: () => void;
    testId?: string;
}
