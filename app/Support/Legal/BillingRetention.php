<?php

declare(strict_types=1);

namespace App\Support\Legal;

use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 課金取引記録の保持年数 (config/legal.php) への**唯一の解決点 (SSOT)**。
 *
 * この数値は「環境ごとに変えてよい運用値」ではなく、**法務文書 (/privacy) が宣言する
 * 値そのもの**である。読む場所が分岐すると「規約が宣言した年数」と「実際に消える年数」が
 * 静かにズレる — 利用者から見て検証不能な形で規約違反が起きる。よって
 * `config('legal.billing_retention_years')` を読んでよいのは本クラス 1 箇所だけとし、
 * それを `tests/Architecture/BillingRetentionConfigSingleSourceTest.php` が
 * deny-by-default で機械固定する (テストクラスへの参照は app → tests の import を
 * 生むため {@see} では書かない)。
 *
 * - 状態も DB 参照も持たない (設定アクセサ + fail-fast のみ)。
 * - 0 以下は設定漏れであり、そのまま threshold を計算すると**未来の時刻**が閾値になり
 *   「まだ保持すべき記録を消す」向きに壊れる。よって **fail-fast** する
 *   (fail-open にしない)。
 *
 * 対象外: 問い合わせ (Inquiry) の保持日数 `legal.inquiry_retention_days` は別概念であり
 * 本クラスは一切関与しない (所有者は inquiry:purge)。
 */
final class BillingRetention
{
    /**
     * 保持年数。
     *
     * @throws \InvalidArgumentException 未設定 / 非整数 / 0 以下のとき
     */
    public static function years(): int
    {
        /** @var mixed $years */
        $years = config('legal.billing_retention_years');
        Assert::integer($years, 'config(legal.billing_retention_years) must be an int.');
        Assert::greaterThan($years, 0, 'config(legal.billing_retention_years) must be positive.');

        return $years;
    }

    /**
     * 保持期限の閾値。**これ以前 (境界を含む) の起算日時を持つ記録は期限超過**である。
     *
     * 年の加減算は暗黙 overflow を禁止する規約に従い `subYearsNoOverflow` を使う
     * (CarbonOverflowArithmeticGateTest が検出する)。
     */
    public static function threshold(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subYearsNoOverflow(self::years());
    }
}
