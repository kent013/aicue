<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * EnsureEmailIsVerifiedOrBack の文脈別文言/戻り先を型で固定する。
 * 文字列引数 + match default => throw 方式は配線 typo が本番 500 になるため不採用。
 * (route 定義側の context 文字列は tryFrom で受け、未知値は fail-closed で既定へ倒す)
 */
enum EmailVerificationGateContext: string
{
    case Invite = 'invite';
    case OrganizationStore = 'organization-store';

    public function message(): string
    {
        return match ($this) {
            self::Invite => 'メールアドレスの認証が完了していないため招待を送信できません。認証メールをご確認ください。',
            self::OrganizationStore => 'メールアドレスの認証が完了していないため組織を作成できません。認証メールをご確認ください。',
        };
    }

    /**
     * referer 不在/越境時の安全な戻り先 (named route)。
     *
     * 両 context とも verification.notice に統一する: 業務 route (organizations.create 等) は
     * verified / require-active-subscription 配下で、未認証ユーザーが直接到達すると再 302 され
     * 最初の error flash が最終画面まで残らない。verification.notice はゲート外かつ
     * 「認証してください」画面そのものなので、未認証ユーザーが必ず到達でき文脈的にも正しい。
     */
    public function fallbackRouteName(): string
    {
        return match ($this) {
            self::Invite => 'verification.notice',
            self::OrganizationStore => 'verification.notice',
        };
    }
}
