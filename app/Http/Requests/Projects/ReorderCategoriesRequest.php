<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Category 並べ替え。payload は当該 project の category id の順序配列。
 * 集合一致 (distinct・過不足なし) の検証は Service (Project ロック後) で行うため、
 * Request は形式 (配列 + 各要素 integer) のみ検証する。
 */
class ReorderCategoriesRequest extends FormRequest
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
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ], $this->protectedKeyMissingRules());
    }
}
