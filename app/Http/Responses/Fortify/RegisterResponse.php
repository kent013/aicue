<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Onboarding\IntendedPlanResolver;
use App\Support\Auth\EmailVerificationContinuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Webmozart\Assert\Assert;

/**
 * 登録直後のレスポンス (Fortify contract bind)。着地は 3 通り:
 *
 *  - unverified 登録 (通常登録・招待 fallback) → verification.notice (「認証してください」画面)。
 *    Fortify 標準は config('fortify.home') (= /dashboard) へ intended redirect するが、
 *    dashboard は `verified` middleware で結局 verification.notice へ弾かれるため直接誘導する
 *  - 招待成立で verified 済みの登録 (正典 v1 i16) → app.entry (認証促し画面を経由させず
 *    組織解決の正規入口へ決定論的に送る。判定は hasVerifiedEmail() のみ)
 *  - XHR (JSON) → 201 (Fortify 標準の後方互換。verified か否かで変えない)
 *
 * P7 の追加責務 (session 副作用のみ。初期組織の生成は CreateNewUser の tx 内で完結済み):
 *   - 通常登録: pending のプラン意図を**自分が Owner の初期組織**へ promote し、
 *     verify ソフトゲートの継続導線 (EmailVerificationContinuation) にその組織 id を保持する。
 *   - 招待受諾成立 (= Owner の組織を持たない): 料金表由来の pending を forget し、
 *     継続導線も張らない (招待組織へ参加するだけのユーザーに契約導線を出さない)。
 * session 副作用は XHR (201) 経路でも同じく先に実行してから応答を返す。
 */
final class RegisterResponse implements RegisterResponseContract
{
    public function __construct(
        private readonly IntendedPlanResolver $intendedPlanResolver,
    ) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 招待受諾は CreateNewUser の tx 内で完了しており、成立時は初期組織を作らない。
        // ★種別フラグ (旧 `is_personal`) は撤去済み (家系裁定 AG-038) なので、
        //   「所属組織の有無」では判定できない — 招待経由の利用者も所属組織を 1 件持つ。
        //   判定軸は **その利用者が Owner の組織かどうか**である。初期組織は必ず本人が Owner で、
        //   招待は Owner を与えないため、料金表由来のプラン意図を他人の組織へ移送してしまう
        //   経路が構造的に消える。
        $initialOrganization = $user->organizations()->orderBy('organizations.id')->get()
            ->first(static fn (Organization $organization): bool => $user->organizationRole($organization) === OrganizationRole::Owner);

        if ($initialOrganization instanceof Organization) {
            // pending → org-scoped へ移送 (pending は必ず forget で消費される)。
            $this->intendedPlanResolver->promotePendingToOrganization($initialOrganization);
            // 生 URL ではなく組織 id のみ保持する (参照時に membership 確認 + route 再構築)。
            EmailVerificationContinuation::remember($request->session(), $initialOrganization->id);
        } else {
            // 招待経由: 料金表由来の pending が残っていても消費しない (stale 防止)。
            $this->intendedPlanResolver->forgetPending();
        }

        if ($request->wantsJson()) {
            return new JsonResponse('', 201); // XHR は Fortify 標準と同じ後方互換 (verified か否かで変えない)
        }

        // 招待経由 (i16) で verified 済みなら「認証してください」画面を経由させない。
        // verification.notice へ送っても Fortify の prompt が fortify.home へ bounce するため
        // 詰みはしないが、redirect()->intended() の stale URL に依存した着地になるのを避け、
        // 組織解決の正規入口 (app.entry = /go) へ決定論的に送る。
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('app.entry');
        }

        return redirect()->route('verification.notice');
    }
}
