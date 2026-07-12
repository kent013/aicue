<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * プロジェクト作成。Policy 検証は Controller 側 (Gate::authorize) で行う
 * (FormRequest は validation 単独責務 = テンプレート規約)。
 *
 * custom_team_id / organization_id 等の tenant キーは ProhibitsProtectedKeys の
 * missing rule で拒否する (Default Team は Service が自動割当)。
 */
class StoreProjectRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:2000'],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        // UI ラベル (Projects/Create.svelte「プロジェクト名」) と揃える。
        // グローバル attributes の 'name' => '名前' より優先される局所上書き。
        // description はグローバルの「説明」がラベルと一致するため上書き不要。
        return ['name' => 'プロジェクト名'];
    }
}
