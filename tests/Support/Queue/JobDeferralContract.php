<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/**
 * 退避 (release) 終端の標準形 v1 の契約表 (lctl 裁定 AG-081)。
 *
 * **これは allowlist ではなく「検査対象の定義」である**。縮小は検出力の縮小であり、
 * 設計変更として PR に理由を書くこと (セキュリティ不変条件 1 の sink 契約表と同じ扱い)。
 *
 * ■ 棚卸しが PR レビューの義務になるもの
 *   - `RELEASING_MIDDLEWARE` … **Laravel を更新したら**退避する job middleware が
 *     増えていないか棚卸しする (機械検出できない)。
 *   - `ADD_METHODS` … **Carbon を更新したら**単位固定の加算メソッドが増えていないか棚卸しする。
 *
 * ■ `ADD_METHODS` に入れないもの (意図的)
 *   - 汎用 `add()` / `add('month', 1)` … 単位が引数で決まるので静的に検査できない。
 *   - overflow variant (`addMonths` / `addYears` …) … AGENTS.md 実装規約が禁止しており、
 *     `CarbonOverflowArithmeticGateTest` が別途落とす (本表で重複して見ない)。
 *   - `sub` 系 … push 時点で既に期限切れの payload を作れてしまい、AG-081 が避けようとした
 *     「ワーカーが 1 度も実行せず即失敗」をそのまま再現する。
 */
final class JobDeferralContract
{
    /**
     * 退避 (release) を行う vendor job middleware の短縮クラス名。
     *
     * vendor 実読 (laravel/framework v13.18.0) でいずれも `$job->release(...)` を呼ぶことを確認済み。
     *
     * @var list<string>
     */
    public const RELEASING_MIDDLEWARE = [
        'RateLimited',
        'RateLimitedWithRedis',
        'ThrottlesExceptions',
        'ThrottlesExceptionsWithRedis',
        'WithoutOverlapping',
    ];

    /**
     * 退避しない (= マーカーに数えない) job middleware。負例の pin 用。
     *
     * `Skip` / `SkipIfBatchCancelled` は delete、`FailOnException` は fail であり release しない。
     *
     * @var list<string>
     */
    public const NON_RELEASING_MIDDLEWARE = ['Skip', 'SkipIfBatchCancelled', 'FailOnException'];

    /**
     * container 解決形の呼び出し名 (`X::class` を引数に取るときだけマーカーになる)。
     *
     * @var list<string>
     */
    public const CONTAINER_RESOLVERS = ['app', 'resolve', 'make'];

    /**
     * C4 が受理する時計起点 (閉集合)。
     *
     * いずれも「呼び出し時点の時刻」であり、push 時に 1 度だけ評価されるため基準時刻が push 時刻になる。
     *
     * @var list<string>
     */
    public const CLOCK_SOURCES = ['now', 'Carbon::now', 'CarbonImmutable::now', 'Date::now'];

    /**
     * C4 が受理する加算メソッド (引数 1 個・単位固定の閉集合)。
     *
     * @var list<string>
     */
    public const ADD_METHODS = [
        'addSeconds',
        'addMinutes',
        'addHours',
        'addDays',
        'addWeeks',
        'addMonthsNoOverflow',
        'addQuartersNoOverflow',
        'addYearsNoOverflow',
    ];

    /**
     * 台帳の mode 値域。
     *
     * @var list<string>
     */
    public const MODES = ['NO_DEFERRAL', 'DEFERS'];
}
