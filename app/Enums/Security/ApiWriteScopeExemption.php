<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 外部向け API の変更系 route が書き込み資格 (`api-key.ability:write`) を
 * 持たないことが正しいと裁定した経路の免除目録 (既定拒否)。
 *
 * 免除できるのは「別の専用資格で判定している」場合だけである。
 * 「認証さえ通っていれば書ける」経路は免除できない。
 */
enum ApiWriteScopeExemption: string
{
    case DedicatedSessionRevokeScope = 'dedicated_session_revoke_scope';

    public function rationale(): string
    {
        return match ($this) {
            self::DedicatedSessionRevokeScope => '自分の CLI セッションの失効は書き込み資格ではなく'
                .'専用の資格 (session.revoke) で判定する。書き込み資格を要求すると'
                .'「読み取りだけの接続が自分のログアウトをできない」詰みになる。'
                .'専用資格を実際に見ていることは免除の前提として機械検査する。',
        };
    }
}
