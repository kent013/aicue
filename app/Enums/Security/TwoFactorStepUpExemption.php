<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「route 名に `two-factor` を含む route が recent-auth (step-up) を持たないことが正しい」と
 * 裁定された理由の分類。
 *
 * `tests/Architecture/TwoFactorStepUpInventoryTest.php` が deny-by-default で
 * 「recent-auth を持つ」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★case は「route の識別子」ではなく「**免除してよい理由の型**」である
 *   (ThrottleCoverageExemption と同じ流儀。1 route 1 case にすると enum が route 名の
 *    写しになり、「同じ理由の免除が増えていないか」という目録の主目的が消える)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「recent-auth を貼るべき route」である。
 */
enum TwoFactorStepUpExemption: string
{
    /**
     * 未認証 (guest) で到達する第二要素チャレンジ面。
     *
     * 適用条件 (すべて満たすこと):
     *  - route middleware に `guest:` guard を持ち、認証済みでは到達できない
     *  - session に認証主体が存在せず、**step-up の概念が定義不能**である
     *  - その route 自体が第二要素の検証側 (satisfier) であり、
     *    自分自身に step-up を要求すると構造的に詰む
     */
    case PreAuthChallengeSurface = 'pre_auth_challenge_surface';

    /**
     * 成立に「その場では生成できない秘密の所持証明」を要求する route。
     *
     * 適用条件 (すべて満たすこと):
     *  - 成立条件が TOTP コード等の**所持証明**であり、session 保持だけでは成立しない
     *  - 応答が秘密を**開示しない**
     *  - 既存の第二要素を**除去・差し替えしない**
     */
    case ProofOfSecretPossessionRequired = 'proof_of_secret_possession_required';
}
