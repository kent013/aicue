<?php

declare(strict_types=1);

namespace App\Enums\EnterpriseSso;

/**
 * 接続の管理操作を拒否した理由。
 *
 * ★企業ログインの拒否 ({@see RejectionReason}) と**別の型**である。
 *   あちらは未認証の経路なので応答を一様にするが、**こちらは認可を通った運営操作**なので
 *   「何が起きたのか」を画面へ具体的に伝える (存在を隠す必要がある相手ではない)。
 */
enum ConnectionTransitionRejection: string
{
    /** 身元が 1 件でもある接続では issuer / client_id を変更できない。 */
    case IdentitiesExistCannotChangeNamespace = 'identities_exist_cannot_change_namespace';

    /** 身元が 1 件でもある接続は物理削除できない (運用は無効化で行う)。 */
    case IdentitiesExistCannotDelete = 'identities_exist_cannot_delete';

    /** 遷移表に無い状態変化を求められた。 */
    case UndefinedTransition = 'undefined_transition';

    public function message(): string
    {
        return match ($this) {
            self::IdentitiesExistCannotChangeNamespace => 'この接続では既に利用者がログインしているため、'
                .'発行者 URL とクライアント ID は変更できません。新しい接続を作成してください。',
            self::IdentitiesExistCannotDelete => 'この接続では既に利用者がログインしているため、削除できません。'
                .'停止する場合は「無効化」を使ってください (登録済みの利用者はそのまま残ります)。',
            self::UndefinedTransition => 'この接続の現在の状態では、その操作を実行できません。'
                .'画面を再読み込みして状態を確認してください。',
        };
    }
}
