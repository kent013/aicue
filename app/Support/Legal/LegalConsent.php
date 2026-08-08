<?php

declare(strict_types=1);

namespace App\Support\Legal;

use Webmozart\Assert\Assert;

/**
 * 利用規約・プライバシーポリシーの同意バージョンの **単一解決点 (SSOT)**。
 *
 * 同意バージョンは users.consent_version / inquiries.consent_version へ
 * 「どの版に同意したか」の証跡として forceFill で記録される。記録側が config を
 * 個別に直読すると形が分岐し (実際に 3 形へ分岐していた)、空版の fail-fast が
 * 一部経路にしか掛からない状態が生まれる。**版を決める場所をここ 1 箇所に閉じる**。
 *
 * - 状態も DB 参照も持たない (設定アクセサ + fail-fast のみ)。
 * - 空版 ('') は設定漏れであり、空版で証跡を書くと「どの版に同意したか」が事後に
 *   決定不能になる。よって**書き込み時点で fail-fast** する (CreateInquiryAction が
 *   単独で持っていた不変条件を全経路へ昇格させたもの)。
 * - 呼び出し元は tests/Architecture/LegalConsentVersionSingleSourceTest.php が
 *   exact-fit の inventory で固定する (新しい同意書き込み経路は登録が必須)。
 *
 * 対象外: 課金の自動購入同意 (config('billing.auto_recharge.consent_version')) は
 * 名前が似ているだけの別概念であり、本クラスは一切関与しない。
 */
final class LegalConsent
{
    /**
     * 現在の同意バージョン。
     *
     * @return non-empty-string
     *
     * @throws \InvalidArgumentException 未設定 / 非文字列 / 空文字のとき
     */
    public static function version(): string
    {
        $version = config()->string('legal.consent_version');
        Assert::stringNotEmpty($version, 'legal.consent_version must be configured');

        return $version;
    }
}
