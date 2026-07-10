<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SSO provider の「再認証 (step-up) satisfier としての保証レベル」分類。
 *
 * recent-auth の再SSO satisfier に使える provider を能力で区別する:
 *
 * - FreshAuthVerifiable: OIDC 等で `auth_time` を署名込みで検証でき、IdP の fresh-auth を
 *   暗号的に保証できる (アプリ側に id_token verifier の実装がある場合のみ名乗れる)。
 * - FreshAuthPromptOnly: IdP に再認証を促し (prompt=login 等)、OAuth round-trip の完了で
 *   本人性 (provider_user_id ↔ social_accounts) を確認できるが、RP 側で fresh-auth を
 *   暗号的に検証はできない。テンプレート標準の Socialite フローはこれに当たる。
 * - IdentityOnly: identity 継続性しか示せず再認証を促す手段も無い (例: `auth_time` を
 *   返さない OAuth-only provider)。**step-up satisfier として不可。**
 *
 * capability は config('template.social_providers.{provider}.capability') で宣言する。
 * id_token verifier を実装したアプリは該当 provider を FreshAuthVerifiable へ引き上げ、
 * 本 enum の isStepUpSatisfier() を FreshAuthVerifiable のみに絞ることを推奨する
 * (参照実装: aigenba IdTokenVerifier / spirux GoogleIdTokenVerifier)。
 */
enum ProviderCapability: string
{
    case FreshAuthVerifiable = 'fresh_auth_verifiable';
    case FreshAuthPromptOnly = 'fresh_auth_prompt_only';
    case IdentityOnly = 'identity_only';

    /**
     * 機微操作 gate の step-up satisfier として利用可能か。
     *
     * テンプレート最小実装は「OAuth round-trip + 本人性バインド」を satisfier と認める
     * (session ハイジャック対策としては有効: 攻撃者は IdP セッションを持たない)。
     * IdentityOnly は再認証を促す手段が無いため常に不可。
     */
    public function isStepUpSatisfier(): bool
    {
        return $this !== self::IdentityOnly;
    }
}
