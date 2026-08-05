<?php

declare(strict_types=1);

namespace App\Services\Auth\EmailTrust;

use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * email 所有を IdP が検証済みで、かつテナント管理者が任意の email を claim できない
 * provider 向けの方針。IdP の主張をそのまま検証済みとして扱う。
 */
final class ConfirmedEmailTrustPolicy implements EmailTrustPolicy
{
    public function trustsEmail(SocialiteUser $socialiteUser): bool
    {
        return true;
    }
}
