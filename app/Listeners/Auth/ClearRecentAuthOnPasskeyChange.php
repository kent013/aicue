<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Security\RecentAuthState;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * credential 集合の変化 = recent-auth 失効 (2026-08-04 裁定 A)。
 *
 * パスキーは単独でログインできる強い資格であり、集合が変わったら
 * 直前に済ませた本人確認は失効させる、という家系統一原則
 * (統一原則のほうが複数年の保守で分類漏れ事故を生みにくく、UX の実害は
 *  登録直後のタップ 1 回程度、という Codex 判定 A に基づくオーナー裁定)。
 *
 * **強化オプション (新規登録直後のパスキーを即 re-step-up の satisfier に使えなくする) は
 * 裁定で明示的に見送られている。実装しないこと。**
 * 再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時。
 *
 * 本 listener は RecentAuthState::clear() の初の production 利用者である
 * (docblock は「認証要素変更後に失効させる」と宣言していたが呼び出し元が無かった)。
 *
 * ⚠ EnsureLoginMethodRemains がトランザクション内で $next() を実行するため、
 * PasskeyDeleted はそのトランザクション内で発火する。ロールバック時には
 * session 側の clear() だけが残りうるが、これは「再認証を余計に 1 回要求する」
 * 方向の誤差であり fail-safe。
 *
 * ⚠ 本 listener は HTTP session を前提とする。将来これらのイベントが
 * CLI / queue / admin cleanup から発火しても壊れないよう session の利用可否を確認する
 * (既存 UpdateUserPassword::deleteOtherSessionRecords() と同じガード作法)。
 */
final class ClearRecentAuthOnPasskeyChange
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
    ) {}

    public function handleRegistered(PasskeyRegistered $event): void
    {
        $this->clearIfSessionAvailable();
    }

    public function handleDeleted(PasskeyDeleted $event): void
    {
        $this->clearIfSessionAvailable();
    }

    private function clearIfSessionAvailable(): void
    {
        if (! app()->bound('session') || ! session()->isStarted()) {
            return;   // CLI / queue 文脈では session 操作をしない
        }

        $this->recentAuthState->clear();
    }
}
