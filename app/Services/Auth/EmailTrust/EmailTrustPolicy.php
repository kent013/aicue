<?php

declare(strict_types=1);

namespace App\Services\Auth\EmailTrust;

use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * SSO provider が主張する email を「IdP 側で検証済み」として信頼してよいかの方針。
 *
 * **Confirmed の判定基準 (契約)**:
 *   provider が当該 email の **所有を検証済み** であり、かつ
 *   **テナント管理者が任意の email を claim できない** こと。
 *   この 2 条件を満たす provider のみ、IdP の主張だけで email_verified_at を立ててよい。
 *
 * 差し替え可能にしてある理由 = nOAuth 対策のキルスイッチ。
 * 例: Microsoft Entra ID のテナント管理者は未検証の email claim を任意に設定でき、
 * 他社ドメインの email を主張できる。そのため Microsoft は Unconfirmed 側に置く。
 *
 * 宣言は config('template.social_providers.{provider}.email_trust')。
 * 未宣言・解釈不能は Unconfirmed に倒す (fail-closed)。
 */
interface EmailTrustPolicy
{
    /** IdP の主張する email を検証済みとして扱ってよいか */
    public function trustsEmail(SocialiteUser $socialiteUser): bool;
}
