<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * P8a: オートリチャージ用カード登録 (Checkout mode=setup) の開始。
 *
 * attempt_token は render 単位の ULID (二重 submit の台帳冪等アンカー)。
 * 認可は Controller の Gate::authorize('manageBilling') が担う。
 */
final class StartAutoRechargeSetupRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_replace([
            'attempt_token' => ['required', 'ulid'],
        ], $this->protectedKeyMissingRules());
    }
}
