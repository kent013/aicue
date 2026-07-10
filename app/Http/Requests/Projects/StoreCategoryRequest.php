<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Category 作成。
 *
 * - 親 FK (project_id) は URL から導出するため payload では受け付けない (missing rule)
 * - sort_order は Service が末尾採番する契約のため入力から除外 (rules 外 = 送っても無視)
 * - name の project 内ユニークは通常入力を Request で 422 に、並行競合は Service の
 *   ロック後再検査 (assertNameUnique) が 422 に先回りする二段構え
 */
class StoreCategoryRequest extends FormRequest
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
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;

        return array_merge([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('categories', 'name')->where('project_id', $projectId),
            ],
        ], $this->protectedKeyMissingRules());
    }
}
