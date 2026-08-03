<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\Billing\SignupFundingChoice;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webmozart\Assert\Assert;

/**
 * Stripe Checkout 開始。Policy 検証 (manageBilling) は Controller 側 (Gate::authorize)。
 *
 * plan_code は「ユーザーがどのプランを購入するか」の選択値であり、tenant/状態キーではない
 * (organizations.plan_code への反映は webhook 同期のみ。この値で直接書き換えることはない)。
 *
 * P9: `subscription_attempt_token` (冪等 token) を必須にする。単一契約 route が
 * Plans 経路 (funding 非提示) と Onboarding 経路 (funding 2 択) の両方を宿すため
 * `funding_choice` は **nullable** (null = 従来の契約 checkout = PM 流用しない)。
 * `funding_choice=auto_recharge` のときだけ現行 `consent_version` との完全一致を要求する
 * (不一致・欠落は 422 = recordPreConsent にも Stripe にも到達しない fail-closed)。
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
            // Str::ulid() は大文字 Crockford base32 を含むため lowercase regex 不可
            // → Laravel の 'ulid' ルールを使う。
            'subscription_attempt_token' => ['required', 'ulid'],
            'funding_choice' => [
                'nullable',
                'string',
                Rule::in(array_map(
                    static fn (SignupFundingChoice $choice): string => $choice->value,
                    SignupFundingChoice::cases(),
                )),
            ],
            'consent_version' => [
                'required_if:funding_choice,'.SignupFundingChoice::AutoRecharge->value,
                'string',
                'max:16',
                Rule::in([$this->currentAutoRechargeConsentVersion()]),
            ],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent_version.required_if' => '自動購入への同意が必要です。',
            'consent_version.in' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。',
        ];
    }

    private function currentAutoRechargeConsentVersion(): string
    {
        $version = config()->string('billing.auto_recharge.consent_version');
        Assert::stringNotEmpty($version, 'config billing.auto_recharge.consent_version は非空で設定してください');

        return $version;
    }
}
