<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\DataTransferObjects\Account\AccountDeletionStateDto;
use App\DataTransferObjects\Organizations\AccountDeletionBlockerDto;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use App\Support\Account\AccountDeletionGrace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * プロフィール設定画面 (GET /settings)。
 * 削除前警告用に「退会をブロックしている組織と次の一手」のスナップショットを props で返す。
 */
class ProfileController extends Controller
{
    public function index(Request $request, OrganizationMembershipService $membership): Response
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return Inertia::render('Settings/Index', [
            // 削除前警告用。退会をブロックしている組織と「次の一手」(action)。
            // 表示時点のスナップショット (最終判定は削除時にサーバーが再評価)。
            'accountDeletionBlockers' => $membership->organizationsBlockingDeletion($user)
                ->map(fn (AccountDeletionBlockerDto $blocker): array => $blocker->toArray())
                ->values()
                ->all(),
            // 退会予約 (猶予期間つき削除) の状態。予約中なら取消バナーを出し、削除ボタン群は隠す
            // (凍結 middleware が settings.account.destroy を遮断していることと UI を一致させる)。
            'accountDeletionState' => AccountDeletionStateDto::fromUser($user)->toArray(),
            // 未予約時の主導線ラベル (「N 日後に削除」) に使う。猶予日数の出典はサーバの SSOT
            // 1 箇所だけで、クライアントに日数を literal で持たせない。
            'accountDeletionGraceDays' => AccountDeletionGrace::days(),
            // パスワードカードの出し分け。password 未設定ユーザーに current_password 必須の
            // 変更フォームを出すと必ず失敗する (踏破不能 UI) ため、初回設定フォームへ切り替える。
            'hasPassword' => $user->hasPassword(),
        ]);
    }
}
