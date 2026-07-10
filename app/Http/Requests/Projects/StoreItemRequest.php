<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Item 作成 (Project 配下サンプルリソースの見本)。
 * 親 FK (project_id) は URL から導出するため payload では受け付けない
 * (ProhibitsProtectedKeys の missing rule。存在するだけで 422)。
 */
class StoreItemRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:2000'],
        ], $this->protectedKeyMissingRules());
    }
}
