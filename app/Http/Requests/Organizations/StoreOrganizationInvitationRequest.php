<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRole;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Webmozart\Assert\Assert;

/**
 * メンバー招待 (組織ロール 2 値)。
 * 認可は Controller の Gate::authorize('manageMembers') が唯一の責務。
 * 重複招待の中立検査は Service 側 (TOCTOU になる DB 依存検証を FormRequest に置かない)。
 * 招待は組織ロールだけを運ぶ (役割付き招待は裁定 AG-079 で撤去。編集者 / 撮影者は
 * 参加後に管理画面のロール割当コマンドで付与する)。
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
            // Owner は招待で付与できない (Owner 昇格は transferOwnership のみ)
            'role' => ['required', 'string', Rule::enum(OrganizationRole::class)->except([OrganizationRole::Owner])],
        ], $this->protectedKeyMissingRules());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // 旧契約値 (editor / shooter 等) を送るデプロイ跨ぎタブの回復導線を明示する
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
    public function role(): OrganizationRole
    {
        $role = $this->enum('role', OrganizationRole::class);
        Assert::isInstanceOf($role, OrganizationRole::class);

        return $role;
    }
}
