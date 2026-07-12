<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 組織名更新。Policy 検証は Controller 側 ($this->authorize) で行う
 * (FormRequest は validation 単独責務 = テンプレート規約)。
 */
class UpdateOrganizationRequest extends FormRequest
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
        // UI ラベル (Organizations/Settings.svelte「組織名」) と揃える。
        // グローバル attributes の 'name' => '名前' より優先される局所上書き。
        return ['name' => '組織名'];
    }
}
