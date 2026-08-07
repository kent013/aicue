/**
 * Error ページ (pages/Error.svelte) の props。
 * PHP 側 App\DataTransferObjects\Http\ErrorScreenData::toInertiaProps() と 1:1 で保守する。
 *
 * 共有 props (appName / auth / flash 等) には依存しない:
 * 例外はテナント境界 404 のように HandleInertiaRequests が走る前にも起きるため、
 * Error ページが必要とする値はすべてサーバが明示 props で渡す。
 */
export interface ErrorScreenDestination {
    readonly label: string;
    /** サーバ側で固定した同一オリジンの相対 path (リクエスト入力は混ざらない)。 */
    readonly href: string;
}

/**
 * 非空リスト。PHP 側 DTO の `non-empty-list<ErrorScreenDestination>` と対応する。
 * 空配列を型で拒否することで、「押せる導線が 1 つも無い Error 画面」= 詰み
 * (AGENTS.md 禁止事項 8 の精神) を TS 側でも作れなくする。
 */
export type NonEmptyDestinations = readonly [ErrorScreenDestination, ...ErrorScreenDestination[]];

export interface ErrorScreenProps {
    readonly status: number;
    readonly title: string;
    readonly message: string;
    /** 待ち時間 (秒)。Retry-After が非負整数のときだけ入る。 */
    readonly retryAfterSeconds: number | null;
    readonly destinations: NonEmptyDestinations;
}
