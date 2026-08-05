<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * パスワード**初回設定** (POST /settings/password) の入力検証。
 *
 * 変更 (current_password 必須) は Fortify の PUT /user/password が担う。
 * ここは「password 未設定ユーザーが初めて設定する」経路のみ。
 */
class SetPasswordRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        // 認可は route の auth / recent-auth middleware と Service の fail-closed 判定が担う
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
        // 確認入力 (confirmed) は使わない (表示トグルで代替。UpdateUserPassword と同方針)。
        return array_replace([
            'password' => ['required', 'string', Password::default()],
        ], $this->protectedKeyMissingRules());
    }
}
