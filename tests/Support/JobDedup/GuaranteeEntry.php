<?php

declare(strict_types=1);

namespace Tests\Support\JobDedup;

use App\Enums\Security\JobDedupGuarantee;
use Webmozart\Assert\Assert;

/** 保証側 (結果の一回性を持つ) ジョブの目録エントリ。 */
final readonly class GuaranteeEntry
{
    /**
     * @param  non-empty-list<JobDedupGuarantee>  $mechanisms  永続状態遷移の機構 (複数可)
     * @param  non-empty-list<PreflightRequirement>  $preflights  外部呼び出しごとの再検証点 (複数可)
     * @param  non-empty-string  $rationale  30 文字以上
     *
     * ★ `$mechanisms` が **複数登録できる**ことが要点。
     *   ExecuteAutoRechargeAttemptJob は「台帳付与の一回性 = invoice 単位の冪等キー UNIQUE」と
     *   「attempt 遷移の一回性 = 条件付き UPDATE」という**軸の違う 2 本の保証**を持つ。
     *   単一 mechanism で書くと、どちらか一方が enum の適用条件に一致しない
     *   誤った分類を型付き目録が保持してしまう。
     * ★ `$preflights` も **複数**である。auto-recharge は `StripeInvoiceCreate` と
     *   `StripeInvoicePay` の **2 つ**の外部呼び出しを持つ。1 件しか登録しないと、
     *   もう一方の preflight を削除しても gate が green のままになり
     *   「目録からすべての外部呼び出しを辿れる」が成立しない。
     */
    public function __construct(
        public array $mechanisms,
        public array $preflights,
        public string $rationale,
    ) {
        Assert::notEmpty($mechanisms, '保証機構を 1 つ以上登録すること');
        Assert::notEmpty($preflights, 'preflight を 1 つ以上登録すること (無いなら NoExternalCall)');
        Assert::greaterThanEq(mb_strlen($rationale), 30, '保証側の根拠は 30 文字以上で書くこと');
    }
}
