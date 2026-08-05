<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SetPasswordRequest;
use App\Models\User;
use App\Services\Auth\PasswordCredentialService;
use Illuminate\Http\RedirectResponse;
use Webmozart\Assert\Assert;

/**
 * パスワード**初回設定** (POST /settings/password)。
 *
 * パスキー / ソーシャルログインのみのユーザーがアプリ内でパスワードを持てる唯一の経路。
 * これが無いと「パスワードを設定してください」と案内する CTA がどこにも着地せず、
 * 踏破不能 CTA になる (監査 F-2)。
 */
final class PasswordSetupController extends Controller
{
    public function __construct(
        private readonly PasswordCredentialService $passwordCredentials,
    ) {}

    /**
     * パスワード未設定ユーザーが初めてパスワードを設定する。
     *
     * - 認証手段を**増やす**操作なので EnsureLoginMethodRemains (減らす操作の関門) は付けない。
     *   代わりに recent-auth を必須にし、セッション奪取からの永続化を防ぐ。
     * - password 設定済みの迂回は Service が fail-closed で拒否する
     *   (current_password 必須の変更経路を骨抜きにしない)。
     * - 禁止事項 7 に従い `back()->with(...)` で完結する (intended は使わない)。
     */
    public function store(SetPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $this->passwordCredentials->setInitial($user, $request->string('password')->value());

        return back()->with('success', 'パスワードを設定しました。次回からパスワードでもログインできます。');
    }
}
