<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * プロジェクト更新 (name / description)。Policy 検証は Controller 側で行う。
 * 所属 (custom_team_id) の変更はこのエンドポイントでは受け付けない (missing rule)。
 */
class UpdateProjectRequest extends FormRequest
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
