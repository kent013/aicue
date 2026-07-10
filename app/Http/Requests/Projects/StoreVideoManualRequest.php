<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * VideoManual 作成。
 *
 * 入力名の境界: カテゴリ選択の入力名は保護キー (category_id) と別名の **category**
 * (値は category id、null 可 = 未分類)。category_id を直送すると missing rule で 422。
 * exists の project スコープは検証時点の保証であり、保存時は Service が
 * ロック済み project relation から再解決して associate する (二段構え)。
 */
class StoreVideoManualRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:200'],
            'category' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('project_id', $projectId),
            ],
        ], $this->protectedKeyMissingRules());
    }
}
