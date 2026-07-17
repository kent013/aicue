<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Personal (free) プラン有効化のリクエスト。
 *
 * declaration = 「個人利用であり、法人・チームでの利用ではない」自己申告チェック (必須)。
 * 認可 (manageBilling) は Controller 冒頭の Gate::authorize が担う。
 * mutating (organizations の free entitlement を書く) ため ProhibitsProtectedKeys を配線する
 * (所有権キー = personal_declared_by_user_id 等は Auth から導出し payload 非受理)。
 */
final class ActivatePersonalRequest extends FormRequest
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
            'declaration' => ['required', 'accepted'],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'declaration.required' => '個人利用であることの確認が必要です。',
            'declaration.accepted' => '個人利用であることの確認が必要です。',
        ];
    }
}
