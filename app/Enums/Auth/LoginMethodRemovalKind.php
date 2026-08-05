<?php

declare(strict_types=1);

namespace App\Enums\Auth;

/**
 * 「今から何を除去しようとしているか」の閉じた variant。
 *
 * LoginMethodRemoval (DTO) と LoginMethodInventory の投影評価が共有する判別子。
 * backing value を持たないのは、永続化も HTTP 露出もしない純粋な内部分岐のため
 * (値を持たせると「どこかで文字列と往復している」と誤読される)。
 */
enum LoginMethodRemovalKind
{
    /** 除去しない (現在状態の照会) */
    case None;

    /** password の除去 (将来の password 削除 route 用) */
    case Password;

    /** SSO 連携 1 件の解除 (将来の連携解除 route 用) */
    case Social;

    /** passkey 1 件の削除 */
    case Passkey;

    /** 全 passkey の除外 (不変条件検査用の仮想投影) */
    case AllPasskeys;
}
