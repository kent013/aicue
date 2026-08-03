<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use App\Enums\Billing\SignupFundingChoice;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // P8a (D29(i)): 資金選択。省略時は「あとで決める」相当 = 既存挙動 (dashboard 着地)。
            // `tickets` は UI から出さないが永続値・旧クライアント互換のため受理する。
            'funding_choice' => ['nullable', Rule::in(array_map(
                static fn (SignupFundingChoice $c): string => $c->value,
                SignupFundingChoice::cases(),
            ))],
            // auto_recharge を選んだときのみ同意 version 必須。**activate より前に**現行版との
            // 完全一致を検証して fail-closed する (画面表示と異なる条件での同意記録を排除)。
            'consent_version' => [
                'required_if:funding_choice,'.SignupFundingChoice::AutoRecharge->value,
                'string',
                'max:16',
                Rule::in([config()->string('billing.auto_recharge.consent_version')]),
            ],
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
            'consent_version.required_if' => '自動購入への同意が必要です。',
            'consent_version.in' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。',
        ];
    }
}
