<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Session\Session;

/**
 * 招待を保持したまま認証を跨ぐときの**継続** (正典 v1 i11。参照実装は
 * laravel-claude-template@5dd85a6 の同名クラス。形は隣接する
 * EmailVerificationContinuation と同じ static + Session 引数)。
 *
 * 未ログインの招待リンク経路 (InvitationAcceptanceController::show) が覚えさせ、
 * password 登録 (CreateNewUser) と register 画面の事前入力の解決
 * (OrganizationMembershipService::resolveRegisterPrefillEmail) が拾う。
 *
 * ## 生の鍵をここ以外に書かない
 * 鍵 literal はこのファイル 1 つに閉じ、InvitationContinuationKeySoTTest が機械で固定する。
 * (従来は controller / 登録処理 / 会員サービスの 3 ファイルに生の鍵が散在していた)
 *
 * ## 型衛生
 * session には任意の型が入りうるため、`is_string && !== ''` を満たさないものは
 * 不正値として忘れさせて null を返す (汚染値で登録経路の型契約を壊さない)。
 *
 * ## 持たないもの
 * 認証を抜けた後の着地 (テンプレートの landing()) は移植しない — aicue には継続を見て
 * 着地を分岐する経路が現存しない (思考原則 2)。必要になったらテンプレートの形
 * (token の有効性を見ずに受諾確認画面へ送る — 裁定 AG-113 (b)) で足すこと。
 */
final class InvitationContinuation
{
    /** session の鍵。生の文字列はこのファイルの外 (app/ 配下) に書かない (gate が固定する)。 */
    private const string SESSION_KEY = 'invitation_token';

    /** 招待リンクに到達した guest の token を覚えさせる。 */
    public static function remember(Session $session, string $token): void
    {
        $session->put(self::SESSION_KEY, $token);
    }

    /** 型衛生つきの読み出し (非破壊)。不正値は忘れさせて null を返す。 */
    public static function resolve(Session $session): ?string
    {
        $raw = $session->get(self::SESSION_KEY);

        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        if ($raw !== null) {
            $session->forget(self::SESSION_KEY);
        }

        return null;
    }

    /**
     * terminal 処理 (登録の確定 / stale・invalid 判明時の破棄) で token を落とす (i14)。
     * email 不一致での再試行を許す経路 (validation 422) では呼ばないこと。
     */
    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
