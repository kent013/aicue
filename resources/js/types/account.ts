/**
 * 退会ガードの Inertia props 型。
 *
 * PHP 側 App\Enums\AccountDeletionBlockerAction /
 * App\DataTransferObjects\Organizations\AccountDeletionBlockerDto::toArray() と対で保守する
 * (値集合の一致は tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest が固定する)。
 */

/** PHP: App\Enums\AccountDeletionBlockerAction と対 (値集合を一致させる) */
export type AccountDeletionBlockerAction =
    | "transfer_ownership"
    | "open_billing"
    | "switch_organization_then_open_billing";

/** 退会をブロックしている組織 1 件分 (表示時点のスナップショット) */
export interface AccountDeletionBlocker {
    name: string;
    slug: string;
    actions: AccountDeletionBlockerAction[];
}

/**
 * 退会予約 (猶予期間つき削除・凍結方式) の状態。
 *
 * PHP: App\DataTransferObjects\Account\AccountDeletionStateDto::toArray() と対。
 * 3 値すべてが null なら「予約なし」。graceDays は purgeAfter - requestedAt の導出値で、
 * サーバ側が唯一の出典 (クライアントで日数を計算し直さない)。
 */
export interface AccountDeletionState {
    /** ISO8601。null = 未予約 */
    requestedAt: string | null;
    /** ISO8601。null = 未予約 */
    purgeAfter: string | null;
    graceDays: number | null;
}
