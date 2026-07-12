<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
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
        return ['name' => '組織名'];
    }
}
