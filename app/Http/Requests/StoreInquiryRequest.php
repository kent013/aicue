<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Inquiry\InquiryType;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Rules\Recaptcha;
use App\Services\Captcha\RecaptchaVerifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 公開フォーム (POST /contact) の入力検証。
 *
 * 設計上の重要な不変条件:
 * - honeypot (`website`) は **検証を fail させない**。値が入っていても validation を通し、
 *   Controller が isHoneypotFilled() を見て「保存せず通常成功と同一レスポンス」に倒す
 *   (bot に成否を悟らせない)。captcha を素の required にすると、honeypot を埋めた
 *   captcha 無し bot が Controller 到達前に validation error になり silent success が
 *   壊れるため、honeypot 充填時は空ルール (一切 validation しない) を返す。
 */
class StoreInquiryRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // コピペ時の前後空白で validation fail しないよう scalar email だけ trim。
        // 正規化本体 (lowercase 含む) は EmailNormalizer (CreateInquiryData) に集約。
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => trim($email)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // honeypot 充填時は一切 validation しない (空ルール)。bot を黙って捨てる経路
        // (Controller の silent success) に渡すため、website が配列・長大文字列でも 422 に
        // しない (= silent success を絶対に崩さない)。bot 経路は Controller が Action を
        // 呼ばないため mass-assign は発生しない。
        if ($this->isHoneypotFilled()) {
            return [];
        }

        return array_replace([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'type' => ['required', Rule::enum(InquiryType::class)],
            'source' => ['nullable', 'string', 'max:50'],
            // 利用規約・プライバシーポリシーへの同意必須 (登録 CreateNewUser 踏襲)。
            'terms_accepted' => ['accepted'],
            'g-recaptcha-response' => [
                'required',
                'string',
                new Recaptcha(app(RecaptchaVerifier::class), $this->ip()),
            ],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms_accepted.accepted' => '利用規約・プライバシーポリシーへの同意が必要です。',
            // token 未取得 (script ブロック / ロード失敗 / 失効) はユーザーに再試行を促す。
            // 既定メッセージだと属性名 "g-recaptcha-response" がそのまま露出するため必須。
            'g-recaptcha-response.required' => 'reCAPTCHAの確認に失敗しました。ページを再読み込みのうえ、もう一度お試しください。',
        ];
    }

    public function isHoneypotFilled(): bool
    {
        return $this->filled('website');
    }
}
