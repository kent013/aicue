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
 * メンバーロール変更 (3 値遷移コマンド)。
 * 認可は Controller の Gate::authorize('manageMembers') が唯一の責務
 * (FormRequest では判定しない。authorize(): true は「入力検証のみ担当」の宣言)。
 * Default Project の存在確認は Service トランザクション内 (TOCTOU 封じ) のため、
 * ここでは enum 妥当性のみを検証する。
 * Owner 指定は enum 外 (AdminConsoleRole に owner がない) のため構造的に不可能
 * (Owner 昇格は transferOwnership のみ、の不変条件を型で表現)。
 */
class UpdateOrganizationMemberRoleRequest extends FormRequest
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

    /** 型付きアクセサ (validated 後の値を enum へ narrow して Service に渡す) */
    public function role(): AdminConsoleRole
    {
        $role = $this->enum('role', AdminConsoleRole::class);
        Assert::isInstanceOf($role, AdminConsoleRole::class);

        return $role;
    }
}
