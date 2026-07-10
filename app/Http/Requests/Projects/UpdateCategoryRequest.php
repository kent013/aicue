<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Category;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Category 更新 (name のみ)。
 * unique は self を除外 (ignore)。sort_order は reorder 専用契約のため受けない。
 */
class UpdateCategoryRequest extends FormRequest
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
        $category = $this->route('category');
        $categoryId = $category instanceof Category ? $category->id : 0;

        return array_merge([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('categories', 'name')
                    ->where('project_id', $projectId)
                    ->ignore($categoryId),
            ],
        ], $this->protectedKeyMissingRules());
    }
}
