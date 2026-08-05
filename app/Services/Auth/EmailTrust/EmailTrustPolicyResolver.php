<?php

declare(strict_types=1);

namespace App\Services\Auth\EmailTrust;

use App\Enums\EmailTrustLevel;

/**
 * provider ごとの EmailTrustPolicy を config 宣言から解決する。
 * 宣言は config('template.social_providers.{provider}.email_trust')。
 * 未宣言・解釈不能は Unconfirmed (fail-closed)。宣言漏れは
 * tests/Architecture/SocialProviderTrustPolicyTest.php が CI で先に落とす。
 */
final class EmailTrustPolicyResolver
{
    public function for(string $provider): EmailTrustPolicy
    {
        $level = EmailTrustLevel::fromRaw(
            config('template.social_providers.'.$provider.'.email_trust'),
        );

        return match ($level) {
            EmailTrustLevel::Confirmed => new ConfirmedEmailTrustPolicy,
            EmailTrustLevel::Unconfirmed => new UnconfirmedEmailTrustPolicy,
        };
    }
}
