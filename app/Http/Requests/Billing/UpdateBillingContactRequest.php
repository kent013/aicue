<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * P9: 請求先連絡先 (メール / 宛名) の更新。
 * 認可 (manageBilling) は Controller 側 (Gate::authorize)。組織は current-org スコープ
 * (route parameter を持たないため cross-org 指定が構造的に不能)。
 */
class UpdateBillingContactRequest extends FormRequest
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
            'billing_contact_email' => ['required', 'email:rfc', 'max:255'],
            'billing_contact_name' => ['nullable', 'string', 'max:255'],
        ], $this->protectedKeyMissingRules());
    }
}
