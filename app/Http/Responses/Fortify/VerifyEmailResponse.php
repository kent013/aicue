<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use App\Models\User;
use App\Support\Auth\EmailVerificationContinuation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

/**
 * メール認証完了後の着地 (Fortify contract bind)。
 *
 * 登録由来の continuation (個人組織 id) があれば onboarding.checkout へ復帰し、
 * continuation は verify 完了時に必ず forget する (寿命の terminal)。
 * continuation が無い場合の着地は **Fortify 既定と同値** (`fortify.home` + `?verified=1`)
 * に保つ = 既存の verify 完了フローを後退させない。
 *
 * `redirect()->intended()` の使用はログイン直後フロー (GET の signed URL 踏破) に限られ、
 * 操作系 POST の応答ではない (AGENTS.md 禁止事項 #7 に抵触しない)。
 */
final class VerifyEmailResponse implements VerifyEmailResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): RedirectResponse
    {
        $user = $request->user();
        $continueUrl = EmailVerificationContinuation::resolveUrl(
            $user instanceof User ? $user : null,
            $request->session(),
        );
        EmailVerificationContinuation::forget($request->session());

        if ($continueUrl !== null) {
            return redirect()->to($continueUrl);
        }

        return redirect()->intended(config()->string('fortify.home').'?verified=1');
    }
}
