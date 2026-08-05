<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SSO provider が主張する email を「IdP 側で検証済み」として扱ってよいかの信頼段階。
 *
 * - Confirmed: provider が当該 email の **所有を検証済み** であり、かつ
 *   **テナント管理者が任意の email を claim できない**。IdP の主張だけで
 *   `email_verified_at` を立ててよい。
 * - Unconfirmed: 上記 2 条件のいずれかを満たさない (または不明)。アプリ側で
 *   メール到達確認 (`/email/verify`) を経てから検証済みにする。
 *
 * 宣言は `config('template.social_providers.{provider}.email_trust')`。
 * 未宣言・解釈不能は Unconfirmed に倒す (fail-closed)。
 */
enum EmailTrustLevel: string
{
    case Confirmed = 'confirmed';
    case Unconfirmed = 'unconfirmed';

    /**
     * config 由来の生値を信頼段階へ解決する。
     * 未宣言 (null) ・非文字列・未知文字列はすべて Unconfirmed (fail-closed)。
     */
    public static function fromRaw(mixed $raw): self
    {
        if (! is_string($raw)) {
            return self::Unconfirmed;
        }

        return self::tryFrom($raw) ?? self::Unconfirmed;
    }
}
