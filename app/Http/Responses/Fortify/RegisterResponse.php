<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

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
 * 登録直後のレスポンス (Fortify contract bind)。
 *
 * Fortify 標準は config('fortify.home') (= /dashboard) へ intended redirect するが、
 * dashboard は `verified` middleware で結局 verification.notice へ弾かれる。
 * 登録直後にメール認証を促す導線を明確にするため、未認証ユーザーが必ず到達できる
 * verification.notice (「認証してください」画面) へ直接誘導する。
 * XHR(201) は Fortify 標準と同じ後方互換を維持する。
 *
 * P7 の追加責務 (session 副作用のみ。個人組織生成は CreateNewUser の tx 内で完結済み):
 *   - 通常登録: pending のプラン意図を個人組織へ promote し、verify ソフトゲートの
 *     継続導線 (EmailVerificationContinuation) に個人組織 id を保持する。
 *   - 招待受諾成立 (= 個人組織が存在しない): 料金表由来の pending を forget し、
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

        // 招待受諾は CreateNewUser の tx 内で完了しており、成立時は個人組織を作らない。
        // 「個人組織の有無」が招待経由かどうかの唯一の判定軸 (?-> で握り潰さず分岐を明示する)。
        $personalOrganization = $user->organizations()->where('is_personal', true)->first();

        if ($personalOrganization instanceof Organization) {
            // pending → org-scoped へ移送 (pending は必ず forget で消費される)。
            $this->intendedPlanResolver->promotePendingToOrganization($personalOrganization);
            // 生 URL ではなく組織 id のみ保持する (参照時に membership 確認 + route 再構築)。
            EmailVerificationContinuation::remember($request->session(), $personalOrganization->id);
        } else {
            // 招待経由: 料金表由来の pending が残っていても消費しない (stale 防止)。
            $this->intendedPlanResolver->forgetPending();
        }

        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        return redirect()->route('verification.notice');
    }
}
