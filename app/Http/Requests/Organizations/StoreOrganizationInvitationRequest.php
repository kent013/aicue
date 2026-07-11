<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\AdminConsoleRole;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Webmozart\Assert\Assert;

/**
 * メンバー招待 (3 値遷移コマンド)。
 * 認可は Controller の Gate::authorize('manageMembers') が唯一の責務。
 * 重複招待の中立検査・Default Project 存在確認は Service 側 (TOCTOU になる DB 依存検証を
 * FormRequest に置かない)。project_role はクライアントから受けず role コマンドから導出する。
 */
class StoreOrganizationInvitationRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return array_merge([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::enum(AdminConsoleRole::class)],
        ], $this->protectedKeyMissingRules());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // 旧契約値 (organization_admin 等) を送るデプロイ跨ぎタブの回復導線を明示する
            'role.'.Enum::class => 'ロールの指定が不正です。画面を再読み込みしてやり直してください。',
        ];
    }

    /** 型付きアクセサ (validated 後の値を string へ narrow して Service に渡す) */
    public function email(): string
    {
        $email = $this->validated('email');
        Assert::string($email);

        return $email;
    }

    /** 型付きアクセサ (validated 後の値を enum へ narrow して Service に渡す) */
    public function role(): AdminConsoleRole
    {
        $role = $this->enum('role', AdminConsoleRole::class);
        Assert::isInstanceOf($role, AdminConsoleRole::class);

        return $role;
    }
}
