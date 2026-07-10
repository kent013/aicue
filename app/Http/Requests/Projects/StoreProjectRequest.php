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
}
