<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Auth\PasswordCredentialService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    public function __construct(
        private readonly PasswordCredentialService $passwordCredentials,
    ) {}

    /**
     * パスワード変更の検証と反映、および他デバイスのセッション・remember-me の失効。
     *
     * 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
     * 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)。
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default()],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        // 確定後処理 (hash 保存・監査記録・他デバイス失効・session 行削除) は
        // 初回設定経路 (PasswordSetupController) と共有する
        // (PasswordCredentialService が users.password 確定の単一窓口)。
        // 片方だけに副作用を書くと、もう片方が黙って劣化する (他デバイスが残る等)。
        $this->passwordCredentials->change($user, $input['password']);
    }
}
