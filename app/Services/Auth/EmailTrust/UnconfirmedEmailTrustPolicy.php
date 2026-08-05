<?php

declare(strict_types=1);

namespace App\Services\Auth\EmailTrust;

use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * email 所有の検証を IdP に依存できない provider 向けの方針 (fail-closed 既定)。
 * アプリ側のメール到達確認 (`/email/verify`) を経てから検証済みにする。
 */
final class UnconfirmedEmailTrustPolicy implements EmailTrustPolicy
{
    public function trustsEmail(SocialiteUser $socialiteUser): bool
    {
        return false;
    }
}
