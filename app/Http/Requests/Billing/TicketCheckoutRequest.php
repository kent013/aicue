<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Billing\TicketVolumePrice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * チケット購入 Checkout 開始。payload は count / attempt_token のみ
 * (金額・Price ID・organization_id は受け取らない = tenant キー不信)。
 */
class TicketCheckoutRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true; // 認可は controller の Gate::authorize('manageBilling') が行う
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_merge([
            'count' => [
                'required',
                'integer',
                'min:'.TicketVolumePrice::PURCHASE_MIN_COUNT,
                'max:'.TicketVolumePrice::PURCHASE_MAX_COUNT,
            ],
            'attempt_token' => ['required', 'ulid'],
        ], $this->protectedKeyMissingRules());
    }
}
