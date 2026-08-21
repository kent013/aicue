<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use App\Support\Seo\SeoManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 招待受諾。GET (確認画面) は guest 可 (未ログインは register へ誘導)。POST (受諾) は auth 必須。
 * verified は要求しない (招待された直後の未検証ユーザーも受諾できる)。
 * 受諾には受諾者 email と招待の宛先 email の一致を要求する (権威は
 * OrganizationMembershipService。GET は補助 UX として不一致を事前表示する)。
 */
class InvitationAcceptanceController extends Controller
{
    /**
     * 受諾確認画面 (GET, guest 可)。
     *
     * - token 欠落 (URL に token param 自体が無い) は 404
     * - 無効招待 (不在/取消済/受諾済/期限切れ) は理由を出し分けず組織名も出さない専用ページ
     *   (Invitations/Invalid) を返す。どの無効理由でも同一画面にすることで token オラクルを防ぐ
     *   (未認証の URL 探索で「組織が実在し招待が取り消された」等を識別させない)
     * - 未ログイン + 有効招待: token を session に fail-secure 保存し register へ誘導する
     *   (登録完了時に CreateNewUser が招待組織へ参加させる)
     * - ログイン済 + 有効招待: 受諾確認画面 (組織名 + 受諾ボタン) を表示する
     *
     * タイトル: route 既定は config('seo.app_titles')['invitations.accept'] =「組織への招待」。
     * 無効分岐だけは同じ route で別ページ (Invitations/Invalid) を返すため、
     * SeoManager::setPrivateTitle() で上書きする (config は route 名でしか引けない)。
     * **理由・組織名は開示しない**既存の秘匿契約を守り、固有名にも組織名を混ぜない。
     */
    public function show(Request $request, SeoManager $seo): Response|RedirectResponse
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && $token !== '', 404);

        $invitation = OrganizationInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        // 無効招待は理由非開示の専用ページへ (guest / auth 共通)
        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
            // タブ title は h1「この招待リンクは使用できません」から指示語「この」を落とした形。
            // SeoTitle::compose が ` | {サイト名}` を付けるため、タブ幅を圧迫しない範囲で見出しと揃える
            // (config/seo.php の「h1 と一致させる」規約に対する意図的な短縮。
            //  文言を変えるときは Invitations/Invalid.svelte の h1 も追随させる)。
            $seo->setPrivateTitle('招待リンクは使用できません');

            return Inertia::render('Invitations/Invalid');
        }

        // 未ログイン: token を session に保存して register へ誘導 (受諾は登録完了後)
        if (! $request->user() instanceof User) {
            $request->session()->put('invitation_token', $token);

            return redirect()->route('register');
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // 宛先 email 照合 (補助 UX)。権威は Service (acceptInvitation + joinOrganization)。
        // prop 名は「email が一致するか」だけを表す (受諾可否の全条件ではない)。
        // 規則は OrganizationInvitation::isAddressedTo に集約 (Controller は独自比較式を持たない)。
        // $request->user() は上の guest 分岐で早期 return 済みだが PHPStan L10 のため narrow する。
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);
        $recipientEmailMatches = $invitation->isAddressedTo($user);

        // 不一致時は organizationName を渡さない (null)。DOM で隠すだけでは初期 Inertia payload /
        // devtools から非受信者が組織名を読めてしまうため、payload そのものから組織名を落とす
        // (非受信者への組織の実在・名称の開示面を増やさない)。
        return Inertia::render('Invitations/Accept', [
            'organizationName' => $recipientEmailMatches ? $organization->name : null,
            'token' => $token,
            'recipientEmailMatches' => $recipientEmailMatches,
        ]);
    }

    /** 受諾 (POST)。成否いずれも dashboard へ flash 付きで遷移する */
    public function store(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $request->validate([
            'token' => ['required', 'string'],
        ]);
        $token = $request->input('token');
        Assert::string($token);

        try {
            $organization = $membership->acceptInvitation($token, $user);
        } catch (ValidationException $e) {
            // back 先が GET /invitations/accept (404 になり得る) のため dashboard へ逃がす
            return redirect()->route('dashboard')->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard')->with('success', "「{$organization->name}」に参加しました");
    }
}
