<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webmozart\Assert\Assert;

/**
 * VideoManual 複製 (別名保存)。保存済みシナリオを新タイトル・カテゴリで別 manual 化する。
 *
 * 入力名の境界: カテゴリ選択の入力名は保護キー (category_id) と別名の category (id 値・null 可)。
 * exists の project スコープは検証時点の保証。保存時は Service がロック済み project relation から
 * 再解決して associate する (二段構え)。
 *
 * 認可は Controller の Gate::authorize('duplicate', $manual) に一元化するため authorize() は true。
 * ただし {project} ∈ current org は route の project.in-route-org middleware が
 * FormRequest 検証より前に 404 に落とすため、category exists の project スコープは
 * cross-org/cross-project の存在差を漏らさない (存在オラクル防御)。
 */
class DuplicateVideoManualRequest extends FormRequest
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

    /** 検証済みタイトル (型付きアクセサで mixed を narrow) */
    public function title(): string
    {
        $title = $this->validated('title');
        Assert::string($title);

        return $title;
    }

    /**
     * 検証済みカテゴリ id (null = 未分類)。ConvertEmptyStringsToNull 経由の '' も null 化される。
     * Select 由来の数値文字列も許容する (nullOrIntegerish で narrow)。
     */
    public function categoryId(): ?int
    {
        $category = $this->validated('category');
        Assert::nullOrIntegerish($category);

        return $category === null ? null : (int) $category;
    }
}
