<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Stripe Checkout 開始。Policy 検証 (manageBilling) は Controller 側 (Gate::authorize)。
 *
 * plan_code は「ユーザーがどのプランを購入するか」の選択値であり、tenant/状態キーではない
 * (organizations.plan_code への反映は webhook 同期のみ。この値で直接書き換えることはない)。
 */
class BillingCheckoutRequest extends FormRequest
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
            'plan_code' => ['required', 'string', 'exists:plans,code'],
        ], $this->protectedKeyMissingRules());
    }
}
