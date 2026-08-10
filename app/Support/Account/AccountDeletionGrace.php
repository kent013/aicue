<?php

declare(strict_types=1);

namespace App\Support\Account;

use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 退会 (アカウント削除) の猶予日数 (config/account.php) への**唯一の解決点 (SSOT)**。
 *
 * 猶予日数は「環境ごとに変えてよい運用値」ではなく、利用者に対して
 * 「いつまで取り消せるか」を約束する値である。読む場所が分岐すると
 * 「画面が案内した期日」と「日次バッチが実際に消す期日」が静かにズレるため、
 * `config('account.deletion_grace_days')` を読んでよいのは本クラス 1 箇所だけとし、
 * それを `tests/Architecture/AccountDeletionGraceConfigTest.php` が deny-by-default で
 * 機械固定する (テストクラスへの参照は app → tests の import を生むため {@see} では書かない)。
 *
 * - 状態も DB 参照も持たない (設定アクセサ + 純粋な日付計算のみ)。
 * - 0 以下は設定漏れであり、そのまま `purgeAfter()` を計算すると**予約時刻以前**が期限になる
 *   = 予約した瞬間に期限到来 = 猶予ゼロで物理削除される。よって **fail-fast** する。
 */
final class AccountDeletionGrace
{
    /**
     * 猶予日数。
     *
     * @throws \InvalidArgumentException 未設定 / 非整数 / 0 以下のとき
     */
    public static function days(): int
    {
        /** @var mixed $days */
        $days = config('account.deletion_grace_days');
        Assert::integer($days, 'config(account.deletion_grace_days) must be an int.');
        Assert::greaterThan($days, 0, 'config(account.deletion_grace_days) must be positive.');

        return $days;
    }

    /**
     * 予約時刻から執行期限 (これを過ぎたら日次バッチが物理削除する時刻) を導く。
     * 要件は「**暦日 30 日**」。
     *
     * ★**`addDaysNoOverflow` は使わない**。`NoOverflow` の意味は「上位単位 (月) を越えない」で
     *   あり、日加算に *NoOverflow の意味論を持ち込むと月末で丸められて 30 日未満になりうる
     *   (猶予期間の意味そのものが壊れ、「30 日は取り消せます」という案内が嘘になる)。
     *   **実測 (T142 の mutation M22)**: 本リポジトリの Carbon では `addDaysNoOverflow` は
     *   そもそも**存在しない** (`BadMethodCallException`) ため、設計が想定した「静かに 28 日になる」
     *   壊れ方は起きず、即座に例外になる。したがって現実の危険は *NoOverflow ではなく
     *   **月単位の式へ書き換えること** (`addMonth()` 等) の側にある。
     * ★AGENTS.md の実装規約と `CarbonOverflowArithmeticGateTest` の禁止語彙は
     *   **月・年・四半期**が対象で、日は母集団に入っていない (gate の定数を実読して確認)。
     *   よって「暦日 30 日」であることの保証は `AccountDeletionGraceConfigTest` の
     *   behavioral 検査 (2026-01-31 + 30 日 = 2026-03-02) が担う。
     */
    public static function purgeAfter(CarbonImmutable $requestedAt): CarbonImmutable
    {
        return $requestedAt->addDays(self::days());
    }
}
