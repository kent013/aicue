<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\ApiKeyAbility;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * API キー発行 (組織設定画面)。
 * organization_id / created_by_user_id / key_prefix / key_hash はサーバ導出のため
 * payload では受け付けない (ProhibitsProtectedKeys + named creation method)。
 */
class StoreApiKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::enum(ApiKeyAbility::class)],
        ], $this->protectedKeyMissingRules());
    }
}
