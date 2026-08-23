<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Rules\AssignableOrganizationSlugRule;
use App\Support\Organization\OrganizationSlug;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 組織作成。認可は「認証済みユーザーなら誰でも作成可」のため常に true
 * (FormRequest は validation 単独責務 = テンプレート規約)。
 */
class StoreOrganizationRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'max:255'],
            // ★識別名は**任意**である。省略時は組織名から導出し、導出できなければ
            //   Service が `org-{乱数}` へ倒す (日本語の組織名でも登録できる)。
            //   明示された値は矯正も代替もしない — 予約語・使用済みは 422 で利用者へ返す。
            'slug' => [
                'nullable',
                'string',
                'max:'.OrganizationSlug::MAX_LENGTH,
                new AssignableOrganizationSlugRule,
            ],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        // UI ラベル (Organizations/Create.svelte「組織名」) と揃える。
        // グローバル attributes の 'name' => '名前' より優先される局所上書き
        // (UpdateOrganizationRequest と対称)。
        return ['name' => '組織名', 'slug' => '識別名'];
    }
}
